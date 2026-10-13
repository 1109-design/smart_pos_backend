<?php

namespace App\Console\Commands;

use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Services\SyncProcessor;
use Illuminate\Console\Command;

/**
 * Backfill for the opening-stock broadcast fixed alongside this command:
 * SyncProcessor's 'products' case used to create a new product's
 * 'opening_stock' movement server-side without ever publishing it, so no
 * till pulled it and the till's stock history had no opening line behind
 * the product's quantity. This publishes every such movement that has no
 * stock_movements SyncRecord yet.
 *
 * A movement with no location_id (older till builds never sent one) is
 * attributed to a location only when that provably reconciles it: there's
 * one candidate location (the product's only product_stock row, or else
 * the business's only location) and that location's ledger is short by
 * exactly this movement's quantity. If the location's ledger already
 * accounts for the stock — e.g. someone keyed the same take-on in again as
 * a manual adjustment — tagging it would double-count, so it's left as is.
 */
class PublishOpeningStockMovements extends Command
{
    protected $signature = 'stock:publish-opening-movements
        {--business= : Restrict to one business_id}
        {--dry-run : Show what would change without writing anything}';

    protected $description = 'Publish server-created opening_stock movements that never reached devices, fixing missing locations where unambiguous';

    public function handle(SyncProcessor $processor): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = StockMovement::where('type', 'opening_stock')
            ->whereNotExists(function ($q) {
                $q->from('sync_records')
                    ->where('sync_records.table_name', 'stock_movements')
                    ->whereColumn('sync_records.record_uuid', 'stock_movements.id');
            });

        if ($business = $this->option('business')) {
            $query->where('business_id', $business);
        }

        $published = 0;
        $located = 0;
        $unlocated = [];

        foreach ($query->lazyById() as $movement) {
            if ($movement->location_id === null) {
                $locationId = $this->resolveLocationId($processor, $movement);

                if ($locationId === null) {
                    $unlocated[] = $movement->id;
                } else {
                    $located++;
                    if (! $dryRun) {
                        $movement->update(['location_id' => $locationId]);
                    }
                }
            }

            if (! $dryRun) {
                $processor->broadcastStockMovement($movement);
            }
            $published++;
        }

        $this->info(sprintf(
            '%s %d opening_stock movement(s); %s location on %d.%s',
            $dryRun ? 'Would publish' : 'Published',
            $published,
            $dryRun ? 'would set' : 'set',
            $located,
            $dryRun ? ' [dry run]' : ''
        ));

        if ($unlocated !== []) {
            $this->warn(count($unlocated).' movement(s) still have no location (product in several locations, no safe guess): '.implode(', ', $unlocated));
        }

        return self::SUCCESS;
    }

    private function resolveLocationId(SyncProcessor $processor, StockMovement $movement): ?string
    {
        $stockLocations = ProductStock::where('product_id', $movement->product_id)->limit(2)->pluck('location_id');

        $locationId = $stockLocations->count() === 1
            ? $stockLocations->first()
            : ($stockLocations->isEmpty() ? $processor->soleLocationId($movement->business_id) : null);

        if ($locationId === null) {
            return null;
        }

        $recorded = (float) (ProductStock::where('product_id', $movement->product_id)
            ->where('location_id', $locationId)
            ->value('quantity') ?? 0);
        $ledger = (float) StockMovement::where('product_id', $movement->product_id)
            ->where('location_id', $locationId)
            ->sum('quantity_change');

        return abs(($ledger + (float) $movement->quantity_change) - max(0, $recorded)) < 0.0001 ? $locationId : null;
    }
}
