<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\SyncRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one-time "Reset All Stock" action, shared by every place it can be
 * triggered from — BackOffice Settings (App\Http\Controllers\BackOffice\
 * SettingsController) and a device's own Settings screen
 * (App\Http\Controllers\Api\StockResetController). Whichever origin gets
 * there first consumes the business's single token; the other is locked out
 * for good. See [[smartpos-stock-reset]].
 */
class StockResetService
{
    /**
     * Atomically claim the one-time token for a business. Safe to call from
     * two origins (BackOffice + a device) at nearly the same moment — the row
     * lock means exactly one caller sees `claimed: true`.
     *
     * @return array{claimed: bool, at: ?Carbon, by: ?string}
     */
    public function claim(string $tenantId, string $userId, ?string $businessNameFallback = null): array
    {
        return DB::transaction(function () use ($tenantId, $userId, $businessNameFallback) {
            $business = Business::where('id', $tenantId)->lockForUpdate()->first();

            if (! $business) {
                $business = Business::create([
                    'id' => $tenantId,
                    'name' => $businessNameFallback ?: $tenantId,
                    'stock_reset_at' => now(),
                    'stock_reset_by_user_id' => $userId,
                ]);

                return ['claimed' => true, 'at' => $business->stock_reset_at, 'by' => $userId];
            }

            if ($business->stock_reset_at) {
                return ['claimed' => false, 'at' => $business->stock_reset_at, 'by' => $business->stock_reset_by_user_id];
            }

            $business->forceFill([
                'stock_reset_at' => now(),
                'stock_reset_by_user_id' => $userId,
            ])->save();

            return ['claimed' => true, 'at' => $business->stock_reset_at, 'by' => $userId];
        });
    }

    /**
     * Zero out every product's stock, everywhere, for a business that has
     * already claimed the token above. Each product's flat total and
     * per-location split are both ledger-owned (see [[smartpos-project-map]]
     * / SyncProcessor), so "reset" means writing offsetting `stock_movements`
     * entries that bring every number to zero, not a raw UPDATE — that's what
     * lets devices pick the change up on their next sync and keeps the ledger
     * and the totals it derives in agreement, exactly like every other
     * stock-affecting write in this app.
     *
     * Called synchronously by BackOffice (there is no "device" to do the
     * zeroing locally there). A device instead performs the equivalent loop
     * itself, over its own local catalogue, and pushes the resulting
     * stock_movements through the normal sync pipeline — this method is not
     * on that path.
     *
     * @return int number of products touched
     */
    public function zeroOutAllStock(string $tenantId, string $userId, SyncProcessor $processor): int
    {
        $products = Product::where('business_id', $tenantId)
            ->where('item_type', 'product')
            ->get(['id', 'stock_quantity']);

        $stockByProduct = ProductStock::whereIn('product_id', $products->pluck('id'))
            ->where('quantity', '!=', 0)
            ->get(['product_id', 'location_id', 'quantity'])
            ->groupBy('product_id');

        $resetCount = 0;
        foreach ($products as $product) {
            $locationRows = $stockByProduct->get($product->id, collect());

            if ((float) $product->stock_quantity === 0.0 && $locationRows->isEmpty()) {
                continue;
            }

            $remaining = (float) $product->stock_quantity;
            foreach ($locationRows as $row) {
                $this->recordZeroingMovement($processor, $tenantId, $product->id, -(float) $row->quantity, $row->location_id, $userId);
                $remaining -= (float) $row->quantity;
            }

            // Whatever's left after clearing every known location is stock
            // attributed nowhere in particular (e.g. opening stock entered
            // before this business used multi-location) — one more movement
            // with no location_id brings the flat total the rest of the way.
            if (abs($remaining) > 0.0001) {
                $this->recordZeroingMovement($processor, $tenantId, $product->id, -$remaining, null, $userId);
            }

            $resetCount++;
        }

        return $resetCount;
    }

    private function recordZeroingMovement(SyncProcessor $processor, string $tenantId, string $productId, float $quantityChange, ?string $locationId, string $userId): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'business_id' => $tenantId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'type' => 'adjustment',
            'quantity_change' => $quantityChange,
            'reason' => 'Bulk stock reset (one-time) — BackOffice settings',
            'user_id' => $userId,
        ];

        $processor->process('stock_movements', $uuid, 'upsert', $payload);

        SyncRecord::create([
            'business_id' => $tenantId,
            'table_name' => 'stock_movements',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
