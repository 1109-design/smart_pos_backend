<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Services\SyncHealthReport;
use App\Services\SyncProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Repairs products that sync:health flags as "total vs. sum of locations"
 * mismatches caused by stock_movements with no location_id — lines that
 * count toward the product's total but no location. Two real shapes:
 *
 *  - Duplicate: the location's own ledger already equals its recorded stock,
 *    so the untagged line is extra. Staff saw the location short and keyed
 *    the same stock in again there (HIN007: untagged 48 opening line plus a
 *    48 "Physical Stock Count" at Main Store — total 94, real 46). Fix: tag
 *    the untagged line(s) to the location and post a correcting adjustment
 *    for their sum, so nothing is deleted and the history stays readable.
 *  - Missing piece: the location's ledger is short by exactly the untagged
 *    sum. Fix: tag only.
 *
 * Anything that matches neither is reported and left for a human.
 */
class ResolveUntaggedStockLines extends Command
{
    protected $signature = 'stock:resolve-untagged-lines
        {product? : Product id; omit to check every flagged product of --business}
        {--business= : Business id (required when no product is given)}
        {--dry-run : Show what would change without writing anything}';

    protected $description = 'Fix product totals inflated by stock lines with no location (tag them, and correct proven duplicates)';

    private const EPSILON = 0.0001;

    public function handle(SyncProcessor $processor, SyncHealthReport $report): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($productId = $this->argument('product')) {
            $productIds = [$productId];
        } elseif ($businessId = $this->option('business')) {
            $productIds = array_column($report->stockMismatchesFor($businessId), 'product_id');
        } else {
            $this->error('Pass a product id, or --business= to check every flagged product.');

            return self::FAILURE;
        }

        if ($productIds === []) {
            $this->info('No products with a stock mismatch.');

            return self::SUCCESS;
        }

        foreach ($productIds as $id) {
            $this->resolve($processor, $id, $dryRun);
        }

        return self::SUCCESS;
    }

    private function resolve(SyncProcessor $processor, string $productId, bool $dryRun): void
    {
        $product = Product::find($productId);
        if (! $product) {
            $this->warn("{$productId}: no such product.");

            return;
        }

        $untagged = StockMovement::where('product_id', $productId)
            ->whereNull('location_id')
            ->whereNotIn('type', ['transfer_out', 'transfer_in', 'transfer'])
            ->orderBy('created_at')
            ->get();

        if ($untagged->isEmpty()) {
            $this->line("{$product->name}: no untagged stock lines — needs a manual look.");

            return;
        }

        $locationId = $this->resolveLocationId($processor, $product);
        if ($locationId === null) {
            $this->line("{$product->name}: stocked at several locations — can't tell where the untagged lines belong; needs a manual look.");

            return;
        }

        $untaggedSum = (float) $untagged->sum('quantity_change');
        $locationLedger = (float) StockMovement::where('product_id', $productId)->where('location_id', $locationId)->sum('quantity_change');
        $recorded = (float) (ProductStock::where('product_id', $productId)->where('location_id', $locationId)->value('quantity') ?? 0);
        $locationName = Location::where('id', $locationId)->value('name') ?? $locationId;

        if (abs($locationLedger - $recorded) < self::EPSILON && $untaggedSum > self::EPSILON) {
            $plan = 'duplicate';
            $message = sprintf(
                '%s: %s already holds %s on its own lines; untagged %s is a duplicate → tag to %s and post a −%s correction (total %s → %s).',
                $product->name, $locationName, $this->qty($recorded), $this->qty($untaggedSum),
                $locationName, $this->qty($untaggedSum), $this->qty((float) $product->stock_quantity), $this->qty($recorded)
            );
        } elseif (abs($locationLedger + $untaggedSum - $recorded) < self::EPSILON) {
            $plan = 'missing';
            $message = sprintf(
                '%s: untagged %s is what %s is missing → tag to %s (no correction).',
                $product->name, $this->qty($untaggedSum), $locationName, $locationName
            );
        } else {
            $this->line(sprintf(
                '%s: doesn\'t reconcile (%s lines %s, recorded %s, untagged %s) — needs a manual look.',
                $product->name, $locationName, $this->qty($locationLedger), $this->qty($recorded), $this->qty($untaggedSum)
            ));

            return;
        }

        $this->line(($dryRun ? '[dry run] ' : '').$message);

        if ($dryRun) {
            return;
        }

        DB::transaction(function () use ($processor, $product, $untagged, $untaggedSum, $locationId, $locationName, $plan) {
            foreach ($untagged as $movement) {
                $movement->update(['location_id' => $locationId]);
                $processor->broadcastStockMovement($movement->fresh());
            }

            if ($plan === 'duplicate') {
                $correctionId = (string) Str::uuid();
                $processor->process('stock_movements', $correctionId, 'upsert', [
                    'business_id' => $product->business_id,
                    'location_id' => $locationId,
                    'product_id' => $product->id,
                    'type' => 'adjustment',
                    'quantity_change' => -$untaggedSum,
                    'unit_cost' => $product->cost_price !== null ? (float) $product->cost_price : null,
                    'reason' => "Correction: stock with no location duplicated stock already counted at {$locationName}",
                    'reference_id' => $untagged->first()->id,
                    'user_id' => null,
                ]);
                $processor->broadcastStockMovement(StockMovement::findOrFail($correctionId));
            } else {
                // No new movement to trigger it — recompute the location so
                // its stock row (and every device's copy) reflects the tag.
                $processor->process('stock_movements', $untagged->first()->id, 'upsert', $untagged->first()->fresh()->only([
                    'business_id', 'location_id', 'to_location_id', 'product_id', 'type', 'quantity_change',
                    'unit_cost', 'running_avg_cost', 'reason', 'reference_id', 'attachment_path', 'user_id',
                ]));
            }
        });
    }

    private function resolveLocationId(SyncProcessor $processor, Product $product): ?string
    {
        $stockLocations = ProductStock::where('product_id', $product->id)->limit(2)->pluck('location_id');

        if ($stockLocations->count() === 1) {
            return $stockLocations->first();
        }

        return $stockLocations->isEmpty() ? $processor->soleLocationId($product->business_id) : null;
    }

    private function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
