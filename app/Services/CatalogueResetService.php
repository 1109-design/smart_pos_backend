<?php

namespace App\Services;

use App\Models\Business;
use App\Models\SyncRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one-time "Reset Everything" action in BackOffice Settings — deletes
 * the entire product catalogue, every stock record, and all sales/purchase/
 * transfer/stocktake history for a business, then lets a fresh CSV import
 * (see ProductsController::export()/import()) repopulate it from scratch.
 *
 * Explicitly confirmed with the user rather than assumed, because it is
 * strictly more destructive than the existing "Reset All Stock" action
 * (StockResetService): that one only zeroes stock quantities through
 * offsetting ledger entries and never deletes a row; this one permanently
 * deletes rows — including transactions/payments, i.e. real (possibly
 * ZIMRA-fiscalised) sales history. There is no undo. See
 * [[smartpos-stock-reset]] for the precedent this mirrors (one-time claim,
 * owner-only, type-to-confirm) and [[smartpos-products-export-import]] for
 * the export/import side of this workflow.
 *
 * categories, suppliers, customers, locations, users, loyalty/credit ledgers
 * and tax rates are deliberately left untouched — they are independent
 * master data, not "the catalogue", and categories in particular have to
 * survive for a re-uploaded CSV's category column to match anything.
 */
class CatalogueResetService
{
    /**
     * Every table this action empties, in delete order (children before the
     * parents whose ids they're scoped by — no FK constraints enforce this,
     * but a failure mid-transaction should never leave a parent gone while
     * its children linger). Each entry: [table, column to scope by, list of
     * parent ids that column must be in, whether to publish per-row 'delete'
     * sync_records for connected devices].
     *
     * product_tax_rates has no single-column primary key and isn't synced to
     * devices, so it's handled separately rather than forced into this shape.
     */
    private function tableSpecs(string $tenantId, array $ids): array
    {
        return [
            // These four, plus stock_movements and container_deposit_ledger
            // below, are hard-coded as immutable/append-only on the till
            // (smart_pos/lib/core/sync/sync_service.dart applyDelete() —
            // financial/ledger line items a device never deletes locally no
            // matter what the server says). No point publishing a delete
            // sync_record for them; still deleted server-side raw so
            // BackOffice reports go clean. Any local orphan left behind on
            // a till is invisible in practice — every query into these
            // reaches them only via their now-deleted parent (transaction,
            // product), so nothing surfaces it.
            ['transaction_taxes', 'transaction_id', $ids['transactions'], false],
            ['transaction_items', 'transaction_id', $ids['transactions'], false],
            ['payments', 'transaction_id', $ids['transactions'], false],
            ['transactions', 'business_id', [$tenantId], true],

            ['purchase_order_items', 'purchase_order_id', $ids['purchase_orders'], true],
            ['po_audit_logs', 'po_id', $ids['purchase_orders'], false],
            ['purchase_orders', 'business_id', [$tenantId], true],

            ['stock_take_items', 'stock_take_id', $ids['stock_takes'], true],
            ['stock_takes', 'business_id', [$tenantId], true],

            ['stock_transfer_items', 'stock_transfer_id', $ids['stock_transfers'], true],
            ['stock_transfers', 'business_id', [$tenantId], true],

            ['container_deposit_ledger', 'business_id', [$tenantId], false],

            ['bundle_items', 'bundle_id', $ids['bundles'], true],
            ['bundles', 'business_id', [$tenantId], true],

            ['product_variant_stock', 'variant_id', $ids['variants'], true],
            ['product_variants', 'product_id', $ids['products'], true],
            ['product_stock', 'product_id', $ids['products'], true],

            ['stock_movements', 'business_id', [$tenantId], false],

            ['products', 'business_id', [$tenantId], true],
        ];
    }

    /**
     * Atomically claim the one-time token for a business — same
     * lockForUpdate pattern as StockResetService::claim(), scoped to its own
     * catalogue_reset_at column so this action and "Reset All Stock" lock
     * independently.
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
                    'catalogue_reset_at' => now(),
                    'catalogue_reset_by_user_id' => $userId,
                ]);

                return ['claimed' => true, 'at' => $business->catalogue_reset_at, 'by' => $userId];
            }

            if ($business->catalogue_reset_at) {
                return ['claimed' => false, 'at' => $business->catalogue_reset_at, 'by' => $business->catalogue_reset_by_user_id];
            }

            $business->forceFill([
                'catalogue_reset_at' => now(),
                'catalogue_reset_by_user_id' => $userId,
            ])->save();

            return ['claimed' => true, 'at' => $business->catalogue_reset_at, 'by' => $userId];
        });
    }

    /**
     * Delete every row in every table listed above for one business, and
     * publish a 'delete' sync_record per deleted id (except the two
     * BackOffice-only, unsynced tables) so every connected till removes its
     * own local copy on its next pull — the normal sync pipeline, just with
     * bulk queries instead of SyncProcessor's per-row helper, since this can
     * touch many thousands of rows in one call and a per-row Eloquent
     * delete-plus-ownership-check loop would be far too slow here.
     *
     * @return array<string, int> rows deleted per table, for the summary message
     */
    public function resetEverything(string $tenantId): array
    {
        return DB::transaction(function () use ($tenantId) {
            $ids = [
                'transactions' => DB::table('transactions')->where('business_id', $tenantId)->pluck('id'),
                'purchase_orders' => DB::table('purchase_orders')->where('business_id', $tenantId)->pluck('id'),
                'stock_takes' => DB::table('stock_takes')->where('business_id', $tenantId)->pluck('id'),
                'stock_transfers' => DB::table('stock_transfers')->where('business_id', $tenantId)->pluck('id'),
                'bundles' => DB::table('bundles')->where('business_id', $tenantId)->pluck('id'),
            ];
            $ids['products'] = DB::table('products')->where('business_id', $tenantId)->pluck('id');
            $ids['variants'] = DB::table('product_variants')->whereIn('product_id', $ids['products'])->pluck('id');

            $counts = [];
            foreach ($this->tableSpecs($tenantId, $ids) as [$table, $column, $parentIds, $sync]) {
                $counts[$table] = $this->wipe($table, $column, $parentIds, $tenantId, $sync);
            }

            // product_container_links has two product-referencing columns
            // (a beverage and the container it carries) and no business_id
            // of its own — scope by either side matching this business's
            // now-deleted products, and don't bother re-deriving an id list
            // for a two-column match via the generic wipe() helper above.
            $counts['product_container_links'] = $this->wipeContainerLinks($ids['products'], $tenantId);

            // No single-column primary key and never synced to devices —
            // handled outside the generic loop.
            $counts['product_tax_rates'] = DB::table('product_tax_rates')->whereIn('product_id', $ids['products'])->delete();

            return $counts;
        });
    }

    private function wipe(string $table, string $column, iterable $parentIds, string $tenantId, bool $sync): int
    {
        $parentIds = collect($parentIds);
        if ($parentIds->isEmpty()) {
            return 0;
        }

        $query = DB::table($table)->whereIn($column, $parentIds);

        if (! $sync) {
            return $query->delete();
        }

        $ids = (clone $query)->pluck('id');
        $deleted = $query->delete();
        $this->publishDeletes($table, $ids, $tenantId);

        return $deleted;
    }

    private function wipeContainerLinks(iterable $productIds, string $tenantId): int
    {
        $productIds = collect($productIds);
        if ($productIds->isEmpty()) {
            return 0;
        }

        $query = DB::table('product_container_links')
            ->where(fn ($q) => $q->whereIn('beverage_product_id', $productIds)->orWhereIn('container_product_id', $productIds));

        $ids = (clone $query)->pluck('id');
        $deleted = $query->delete();
        $this->publishDeletes('product_container_links', $ids, $tenantId);

        return $deleted;
    }

    /**
     * @param  Collection<int, string>  $ids
     */
    private function publishDeletes(string $table, $ids, string $tenantId): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        $now = now();
        $ids->chunk(500)->each(function ($chunk) use ($table, $tenantId, $now) {
            SyncRecord::insert($chunk->map(fn ($id) => [
                'business_id' => $tenantId,
                'table_name' => $table,
                'record_uuid' => $id,
                'operation' => 'delete',
                'payload' => json_encode([]),
                'device_id' => null,
                'source_updated_at' => $now,
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }
}
