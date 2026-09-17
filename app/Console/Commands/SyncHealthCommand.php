<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\SyncHealthReport;
use Illuminate\Console\Command;

/**
 * Spec §28 "are all devices synchronized?" reconciliation report. Read-only
 * — see SyncHealthReport for exactly what "Pending"/"Failed" mean and their
 * limits (the server has no visibility into pushes that failed before ever
 * reaching it, only ones that landed and are stuck as a conflict).
 */
class SyncHealthCommand extends Command
{
    protected $signature = 'sync:health {business_id? : Restrict to one business}';

    protected $description = 'Per-device sync reconciliation report: pending pulls, stuck conflicts, oversells, last sync, and a changelog-vs-live-table integrity check';

    public function handle(SyncHealthReport $report): int
    {
        $businessId = $this->argument('business_id');
        $tenantIds = $businessId
            ? [$businessId]
            : Tenant::query()->pluck('id')->all();

        foreach ($tenantIds as $tenantId) {
            $devices = $report->devicesFor($tenantId);

            if (empty($devices)) {
                continue;
            }

            $this->info("Business: {$tenantId}");
            $this->table(
                ['Device', 'Pending', 'Failed', 'Last Sync'],
                collect($devices)->map(fn ($d) => [
                    $d['device_name'],
                    $d['pending'],
                    $d['failed'],
                    $d['last_sync'] ?? 'never',
                ])->all()
            );

            $integrity = $report->integrityFor($tenantId);
            $mismatches = collect($integrity)->where('mismatch', true);
            if ($mismatches->isNotEmpty()) {
                $this->warn('Integrity mismatches (changelog-derived count vs. live table row count):');
                $this->table(
                    ['Table', 'Expected (from changelog)', 'Actual (live rows)'],
                    $mismatches->map(fn ($m) => [$m['table'], $m['expected_from_changelog'], $m['actual_live_rows']])->all()
                );
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
