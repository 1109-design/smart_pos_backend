<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts the GL effect of an approved stock take's variances. A till-side
 * approval creates one 'stocktake' stock_movement per variance line (see
 * the till's stock take report screen), valued at the product's
 * running_avg_cost at approval time — CostService.adjustStock() never sets
 * unit_cost for this movement type, only running_avg_cost.
 *
 * Unlike GrvPostingService there's no separate domain record (a
 * GoodsReceivedVoucher/GrvItem equivalent) for a stock-take variance, so
 * idempotency is keyed directly off the stock_movement's own id as the
 * journal's source_id — sync can redeliver the same movement.
 *
 * Shrinkage (negative variance — physically less stock than the ledger
 * expected) is a real loss: Dr 6050 Stock Loss / Write-offs, Cr 1200
 * Inventory. Found stock (positive variance) posts the same two accounts in
 * reverse — this chart has no separate "inventory gain" account, so an
 * overage nets against the same Stock Loss / Write-offs line rather than
 * needing one of its own.
 */
class StockTakePostingService
{
    public function __construct(private readonly JournalService $journals) {}

    public function recordVariance(StockMovement $movement): void
    {
        if ($movement->type !== 'stocktake' || ! $movement->reference_id) {
            return;
        }

        if (JournalHeader::where('source_type', 'stock_take_variance')->where('source_id', $movement->id)->exists()) {
            return; // already processed — sync can redeliver the same record
        }

        $business = Business::find($movement->business_id);
        if (! $business?->accountingIsLive()) {
            return;
        }

        $transDate = $movement->created_at?->toDateString() ?? now()->toDateString();
        if ($transDate < $business->accounting_go_live_date->toDateString()) {
            return;
        }

        $qtyChange = (float) $movement->quantity_change;
        $unitCost = (float) ($movement->running_avg_cost ?? 0);
        $amount = round(abs($qtyChange) * $unitCost, 4);

        if ($amount <= 0.005) {
            return;
        }

        try {
            DB::transaction(function () use ($movement, $transDate, $qtyChange, $amount) {
                $inventory = GlAccount::where('business_id', $movement->business_id)->where('code', '1200')->first();
                $stockLoss = GlAccount::where('business_id', $movement->business_id)->where('code', '6050')->first();

                if (! $inventory || ! $stockLoss) {
                    Log::warning("Accounting: chart of accounts missing Inventory/Stock Loss for business {$movement->business_id} — skipping stock take variance {$movement->id}.");

                    return;
                }

                $header = $this->journals->createDraft(
                    $movement->business_id,
                    $transDate,
                    'stock_take_variance',
                    $movement->id,
                    'Stock take variance — '.($movement->reason ?? 'count adjustment'),
                );

                if ($qtyChange < 0) {
                    // Shrinkage: physically less stock than the ledger expected.
                    $this->journals->addLine($header, ['gl_account_id' => $stockLoss->id, 'debit' => $amount]);
                    $this->journals->addLine($header, ['gl_account_id' => $inventory->id, 'credit' => $amount]);
                } else {
                    // Found stock: physically more than the ledger expected.
                    $this->journals->addLine($header, ['gl_account_id' => $inventory->id, 'debit' => $amount]);
                    $this->journals->addLine($header, ['gl_account_id' => $stockLoss->id, 'credit' => $amount]);
                }

                $this->journals->post($header);
            });
        } catch (Throwable $e) {
            Log::warning("Accounting: failed to post stock take variance for stock movement {$movement->id}: {$e->getMessage()}");
        }
    }
}
