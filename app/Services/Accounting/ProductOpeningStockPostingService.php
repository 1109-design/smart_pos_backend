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
 * Posts the GL effect of a product's take-on quantity — the inventory-side
 * counterpart to OpeningBalanceService's customer/supplier opening balances.
 * Until this existed, a fresh product created with an initial stock_quantity
 * (BackOffice create form, the till's product form, or a bulk sheet/CSV
 * import — see ProductsController::import()/applyLocationBalance()) got a
 * `stock_movements` row of type 'opening_stock' for the quantity ledger, but
 * nothing ever recorded its dollar value in the books: the business's
 * take-on inventory value was simply missing from the balance sheet.
 *
 * Dr Inventory (1200) / Cr Opening Balance Equity (3020), valued at the
 * movement's own unit_cost (both call sites that create an 'opening_stock'
 * movement already stamp it with the product's cost_price — see
 * SyncProcessor's 'products' case and CostService.adjustStock() on the
 * till). A downward correction (a negative take-on variance, e.g. fixing a
 * typo'd opening balance before go-live) posts the same two accounts in
 * reverse.
 *
 * No Flutter-side port yet — same as StockTakePostingService, this only
 * runs from the sync path once a movement lands on the server, regardless
 * of which app created it. A business cut over to client-side GL posting
 * still gets this one server-side until a client port exists.
 */
class ProductOpeningStockPostingService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly ChartOfAccountsSeeder $chartSeeder,
    ) {}

    public function recordTakeOn(StockMovement $movement): void
    {
        if ($movement->type !== 'opening_stock') {
            return;
        }

        if (JournalHeader::where('source_type', 'product_opening_stock')->where('source_id', $movement->id)->exists()) {
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
        $unitCost = (float) ($movement->unit_cost ?? 0);
        $amount = round(abs($qtyChange) * $unitCost, 4);

        if ($amount <= 0.005) {
            return;
        }

        try {
            DB::transaction(function () use ($movement, $transDate, $qtyChange, $amount) {
                $inventory = GlAccount::where('business_id', $movement->business_id)->where('code', '1200')->first();

                if (! $inventory) {
                    Log::warning("Accounting: chart of accounts missing Inventory for business {$movement->business_id} — skipping opening stock {$movement->id}.");

                    return;
                }

                $equity = $this->openingBalanceEquityAccount($movement->business_id);

                $header = $this->journals->createDraft(
                    $movement->business_id,
                    $transDate,
                    'product_opening_stock',
                    $movement->id,
                    'Opening stock (take-on)',
                );

                if ($qtyChange >= 0) {
                    $this->journals->addLine($header, ['gl_account_id' => $inventory->id, 'debit' => $amount]);
                    $this->journals->addLine($header, ['gl_account_id' => $equity->id, 'credit' => $amount]);
                } else {
                    // Downward correction to a take-on figure — reverse the entry.
                    $this->journals->addLine($header, ['gl_account_id' => $equity->id, 'debit' => $amount]);
                    $this->journals->addLine($header, ['gl_account_id' => $inventory->id, 'credit' => $amount]);
                }

                $this->journals->post($header);
            });
        } catch (Throwable $e) {
            Log::warning("Accounting: failed to post opening stock for stock movement {$movement->id}: {$e->getMessage()}");
        }
    }

    private function openingBalanceEquityAccount(string $businessId): GlAccount
    {
        return $this->chartSeeder->ensureAccount(
            $businessId,
            'Equity',
            "Shareholders' Equity",
            ['code' => '3020', 'name' => 'Opening Balance Equity'],
        );
    }
}
