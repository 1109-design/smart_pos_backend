<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\GoodsReceivedVoucher;
use App\Models\GrvItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purchasing & Cash Vault Blueprint, part A. Turns a synced stock_movement
 * into a real Goods Received Voucher and posts Dr Inventory / Cr GRN
 * Suspense — the same tolerant-of-failure, never-blocks-sync philosophy as
 * SalePostingService. Only ever triggered for a movement that references a
 * real PurchaseOrder (a known supplier); walk-in receiving (no PO) never
 * reaches this at all — see SyncProcessor's 'stock_movements' case.
 *
 * Deliberately does NOT touch Accounts Payable or Product.cost_price here —
 * cost_price is already correctly maintained by the till's own
 * CostService.receiveStock() call that created this movement in the first
 * place, and Accounts Payable is only created when the actual supplier
 * invoice is recorded (part B), clearing this GRN Suspense entry.
 */
class GrvPostingService
{
    public function __construct(private readonly JournalService $journals) {}

    /**
     * @param  float  $rejectedQty  Units the receiving clerk physically
     *                              turned away at delivery (damaged, wrong
     *                              item, etc.) — never part of $movement's
     *                              own quantity_change, since a rejected
     *                              unit never enters inventory. Not a column
     *                              on stock_movements; the till reports it
     *                              only in the sync payload alongside the
     *                              movement, purely for this GRV record.
     */
    public function recordReceipt(StockMovement $movement, float $rejectedQty = 0.0, ?string $rejectionReason = null): void
    {
        if ($movement->type !== 'receive' || ! $movement->reference_id) {
            return;
        }

        if (GrvItem::where('stock_movement_id', $movement->id)->exists()) {
            return; // already processed — sync can redeliver the same record
        }

        $purchaseOrder = PurchaseOrder::where('id', $movement->reference_id)
            ->where('business_id', $movement->business_id)
            ->first();

        if (! $purchaseOrder) {
            return; // not a PO-linked receipt (walk-in) — nothing to post
        }

        $business = Business::find($movement->business_id);
        if (! $business?->accountingIsLive()) {
            return;
        }

        $receivedDate = $movement->created_at?->toDateString() ?? now()->toDateString();
        if ($receivedDate < $business->accounting_go_live_date->toDateString()) {
            return;
        }

        try {
            DB::transaction(function () use ($movement, $purchaseOrder, $receivedDate, $rejectedQty, $rejectionReason) {
                // Not firstOrCreate() — received_date is a date-cast column
                // that (like trans_date elsewhere) gets stored with a
                // spurious time part, so a plain "=" match against a bare
                // "Y-m-d" string silently never finds the row firstOrCreate
                // just inserted, creating a duplicate GRV per receipt
                // instead of grouping same-day receipts onto one voucher.
                $grv = GoodsReceivedVoucher::where('business_id', $movement->business_id)
                    ->where('purchase_order_id', $purchaseOrder->id)
                    ->whereDate('received_date', $receivedDate)
                    ->first();

                if (! $grv) {
                    $grv = GoodsReceivedVoucher::create([
                        'business_id' => $movement->business_id,
                        'purchase_order_id' => $purchaseOrder->id,
                        'received_date' => $receivedDate,
                        'grv_number' => $this->nextGrvNumber($movement->business_id),
                        'supplier_id' => $purchaseOrder->supplier_id,
                    ]);
                }

                $qty = abs((float) $movement->quantity_change);
                $unitCost = (float) ($movement->unit_cost ?? 0);
                $product = Product::find($movement->product_id);

                GrvItem::create([
                    'grv_id' => $grv->id,
                    'stock_movement_id' => $movement->id,
                    'product_id' => $movement->product_id,
                    'product_name' => $product?->name ?? 'Unknown product',
                    'quantity_received' => $qty + $rejectedQty,
                    'quantity_accepted' => $qty,
                    'quantity_rejected' => $rejectedQty,
                    'rejection_reason' => $rejectedQty > 0 ? $rejectionReason : null,
                    'unit_cost' => $unitCost,
                ]);

                $this->post($grv, $movement->business_id, $receivedDate, $qty, $unitCost);
            });
        } catch (Throwable $e) {
            Log::warning("Accounting: failed to post GRV for stock movement {$movement->id}: {$e->getMessage()}");
        }
    }

    private function post(GoodsReceivedVoucher $grv, string $businessId, string $transDate, float $qty, float $unitCost): void
    {
        $amount = round($qty * $unitCost, 4);
        if ($amount <= 0.005) {
            return;
        }

        $inventory = GlAccount::where('business_id', $businessId)->where('code', '1200')->first();
        $grnSuspense = GlAccount::where('business_id', $businessId)->where('code', '2010')->first();

        if (! $inventory || ! $grnSuspense) {
            Log::warning("Accounting: chart of accounts missing Inventory/GRN Suspense for business {$businessId} — skipping GRV {$grv->grv_number}.");

            return;
        }

        $header = $this->journals->createDraft(
            $businessId,
            $transDate,
            'grv',
            $grv->id,
            "Goods received — {$grv->grv_number}",
        );
        $this->journals->addLine($header, ['gl_account_id' => $inventory->id, 'debit' => $amount]);
        $this->journals->addLine($header, ['gl_account_id' => $grnSuspense->id, 'credit' => $amount]);
        $this->journals->post($header);
    }

    private function nextGrvNumber(string $businessId): string
    {
        $prefix = 'GRV-'.now()->year.'-';

        $numbers = GoodsReceivedVoucher::where('business_id', $businessId)
            ->where('grv_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('grv_number');

        $max = $numbers
            ->map(fn (string $n) => (int) substr($n, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }
}
