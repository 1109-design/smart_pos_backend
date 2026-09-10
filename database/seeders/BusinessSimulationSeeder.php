<?php

namespace Database\Seeders;

use App\Models\Business;
use Database\Seeders\Simulation\DailyOperationsSimulator;
use Database\Seeders\Simulation\FinanceSimulator;
use Database\Seeders\Simulation\MasterDataSeeder;
use Database\Seeders\Simulation\PurchasingSimulator;
use Database\Seeders\Simulation\WarehouseOpsSimulator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A full, ongoing business simulation for SmartPOS's demo/QA tenant —
 * "Masimba Hardware & Building Supplies", a Zimbabwean hardware and
 * building-materials retailer that's been live on the system since
 * {@see MasterDataSeeder::GO_LIVE_DATE}. Touches every module in the
 * feature spec (POS sales in USD/ZWG/ZAR, discounts and voids under
 * approval, glass sheet cuts, purchasing → GRV → supplier invoice/payment,
 * requisitions and job costing, multi-branch transfers, monthly stock
 * takes, exchange-rate changes, payroll, the asset register, quotations
 * and invoicing) with plausible day-to-day randomness rather than a fixed
 * fixture set.
 *
 * RESUMABLE BY DESIGN: the end of the simulated range is always "yesterday"
 * (today's trading day isn't over yet), not a fixed date — running this
 * again next week, or in four months, picks up exactly where the last run
 * left off (see `simulation_last_date` in the business's `metadata`) and
 * simulates forward to the new "yesterday". Running it the same day twice
 * is a safe no-op.
 *
 * Master data (locations, staff, catalogue, chart of accounts, ...) is
 * itself idempotent — re-running never duplicates it — so this seeder is
 * safe to include in a normal `db:seed` pass at any time.
 *
 * Queued/broadcast side effects (ZIMRA fiscalisation queueing, realtime
 * dashboard events) are pointless — and, for ZIMRA, actively undesirable —
 * against backdated historical rows, and this environment has neither a
 * queue worker nor a Reverb server running. Both are forced to run inline
 * for the duration of this seeder only; nothing is written to .env.
 */
class BusinessSimulationSeeder extends Seeder
{
    public function run(): void
    {
        config(['queue.default' => 'sync', 'broadcasting.default' => 'log']);

        $this->call(RolesAndPermissionsSeeder::class);

        $context = app(MasterDataSeeder::class)->build();
        $business = Business::find($context->businessId);

        $from = $this->resumeFrom($business);
        $to = Carbon::now()->subDay()->startOfDay();

        if ($from->greaterThan($to)) {
            $this->command?->info('Business simulation already caught up to yesterday — nothing to do.');

            return;
        }

        $daily = app(DailyOperationsSimulator::class);
        $purchasing = app(PurchasingSimulator::class);
        $warehouse = app(WarehouseOpsSimulator::class);
        $finance = app(FinanceSimulator::class);

        $totalDays = $from->diffInDays($to) + 1;
        $this->command?->info("Simulating {$totalDays} day(s) from {$from->toDateString()} to {$to->toDateString()}...");

        $cursor = $from->copy();
        $processed = 0;

        while ($cursor->lessThanOrEqualTo($to)) {
            $day = $cursor->copy();

            DB::transaction(function () use ($daily, $purchasing, $warehouse, $finance, $context, $day) {
                $daily->forDay($context, $day);
                $purchasing->forDay($context, $day);
                $warehouse->forDay($context, $day);
                $finance->forDay($context, $day);
            });

            $processed++;
            $this->checkpoint($business, $day);

            if ($processed % 30 === 0) {
                $this->command?->info("  ...through {$day->toDateString()} ({$processed}/{$totalDays})");
            }

            $cursor->addDay();
        }

        $this->command?->info("Business simulation complete — through {$to->toDateString()}.");
    }

    private function resumeFrom(Business $business): Carbon
    {
        $lastDate = $business->metadata['simulation_last_date'] ?? null;

        return $lastDate
            ? Carbon::parse($lastDate)->addDay()->startOfDay()
            : Carbon::parse(MasterDataSeeder::GO_LIVE_DATE)->startOfDay();
    }

    private function checkpoint(Business $business, Carbon $day): void
    {
        $metadata = $business->metadata ?? [];
        $metadata['simulation_last_date'] = $day->toDateString();
        $business->update(['metadata' => $metadata]);
    }
}
