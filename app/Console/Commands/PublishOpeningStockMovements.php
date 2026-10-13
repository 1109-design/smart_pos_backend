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
 * first attributed to a location when that's unambiguous: the product's
 * only product_stock row, or else the business's only location. Without
 * it, recomputeLocationStock() rebuilds that location's quantity from the
 * ledger and leaves the opening figure out.
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
                $processor->broadcastOpeningStockMovement($movement);
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
            $this->warn(count($unlocated).' movement(s) left without a location — tagging them would not reconcile the location\'s stock (often a duplicate already counted there), or the product is at several locations. Review with stock:resolve-untagged-lines: '.implode(', ', $unlocated));
        }

        return self::SUCCESS;
    }

    private function resolveLocationId(SyncProcessor $processor, StockMovement $movement): ?string
    {
        $stockLocations = ProductStock::where('product_id', $movement->product_id)->limit(2)->pluck('location_id');

        if ($stockLocations->count() === 1) {
            return $stockLocations->first();
        }

        return $processor->soleLocationId($movement->business_id);
    }
}
