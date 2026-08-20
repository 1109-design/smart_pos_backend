<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\SyncRecord;
use App\Services\SyncProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Backfill for the stock-take approval bug fixed alongside this command:
 * approval used to compute variance against the systemQty snapshot taken
 * when the count started, then apply it as a delta on top of whatever the
 * ledger held at approval time. If the real stock had already moved between
 * snapshot and approval, that stacked instead of reconciling — e.g. a
 * count of 4 against a stale snapshot of 2 landed on 6 instead of 4. This
 * command re-reconciles an already-approved stock take's items to exactly
 * their counted quantity, using the same live-ledger baseline the fixed
 * approval code now uses going forward.
 */
class StockTakeReconcile extends Command
{
    protected $signature = 'stocktake:reconcile
        {stockTake? : Stock take UUID or exact title}
        {--list : List recent stock takes instead of reconciling one}
        {--business= : Restrict --list to one business_id}
        {--dry-run : Show what would change without writing anything}';

    protected $description = "Correct an approved stock take's items back to their counted quantity (backfill for the stale-snapshot variance bug)";

    public function handle(SyncProcessor $processor): int
    {
        if ($this->option('list')) {
            return $this->listStockTakes();
        }

        $identifier = $this->argument('stockTake');
        if (! $identifier) {
            $this->error('Pass a stock take UUID or title, or use --list to find one.');

            return self::FAILURE;
        }

        $take = StockTake::with('items')->where('id', $identifier)->first()
            ?? StockTake::with('items')->where('title', $identifier)->first();

        if (! $take) {
            $this->error("No stock take found matching \"{$identifier}\".");

            return self::FAILURE;
        }

        if ($take->status !== 'approved') {
            $this->error("\"{$take->title}\" is '{$take->status}', not approved — nothing to reconcile.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(sprintf(
            'Reconciling "%s" (business %s, location %s)%s',
            $take->title,
            $take->business_id,
            $take->location_id ?? 'none',
            $dryRun ? ' [dry run]' : ''
        ));

        $corrected = 0;

        foreach ($take->items as $item) {
            $product = Product::find($item->product_id);
            if (! $product || ! $product->track_stock) {
                continue;
            }

            $counted = (float) ($item->counted_qty ?? $item->system_qty);

            $currentQty = $take->location_id
                ? (float) StockMovement::where('product_id', $item->product_id)
                    ->where('location_id', $take->location_id)
                    ->sum('quantity_change')
                : (float) $product->stock_quantity;

            $diff = round($counted - $currentQty, 4);

            if (abs($diff) < 0.0001) {
                continue;
            }

            $this->line(sprintf(
                '  %s: currently %s, should be %s (correcting by %s%s)',
                $item->product_name,
                $currentQty,
                $counted,
                $diff > 0 ? '+' : '',
                $diff
            ));

            if (! $dryRun) {
                $uuid = (string) Str::uuid();
                $payload = [
                    'business_id' => $take->business_id,
                    'location_id' => $take->location_id,
                    'product_id' => $item->product_id,
                    'type' => 'adjustment',
                    'quantity_change' => $diff,
                    'reason' => "Stock take reconciliation correction: {$take->title}",
                    'reference_id' => $take->id,
                    'user_id' => $take->approved_by_user_id,
                ];

                // Same pattern StockTakesController::recordVarianceMovement
                // uses: process() writes the movement and recomputes/
                // broadcasts product_stock + products from the ledger, and
                // the SyncRecord makes the correction itself show up in any
                // device's stock movement history on its next pull.
                $processor->process('stock_movements', $uuid, 'upsert', $payload);

                SyncRecord::create([
                    'business_id' => $take->business_id,
                    'table_name' => 'stock_movements',
                    'record_uuid' => $uuid,
                    'operation' => 'upsert',
                    'payload' => $payload,
                    'source_updated_at' => now(),
                    'synced_at' => now(),
                ]);
            }

            $corrected++;
        }

        if ($corrected === 0) {
            $this->info('Nothing to correct — every item already matches its counted quantity.');
        } else {
            $this->info(($dryRun ? 'Would correct ' : 'Corrected ')."{$corrected} item(s).");
        }

        return self::SUCCESS;
    }

    private function listStockTakes(): int
    {
        $query = StockTake::query()->with('location:id,name')->latest()->limit(30);
        if ($businessId = $this->option('business')) {
            $query->where('business_id', $businessId);
        }

        $rows = $query->get()->map(fn (StockTake $t) => [
            $t->id,
            $t->title,
            $t->status,
            $t->location?->name ?? '—',
            $t->approved_at?->toDateTimeString() ?? '—',
        ]);

        $this->table(['ID', 'Title', 'Status', 'Location', 'Approved At'], $rows);

        return self::SUCCESS;
    }
}
