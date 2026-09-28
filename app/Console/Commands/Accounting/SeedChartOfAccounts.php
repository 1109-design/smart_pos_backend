<?php

namespace App\Console\Commands\Accounting;

use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Console\Command;

/**
 * BusinessProvisioner seeds the chart of accounts for every NEW business
 * automatically — this backfills businesses that were provisioned before
 * Phase 11 shipped. Safe to re-run: ChartOfAccountsSeeder::seedForBusiness()
 * is a no-op for a business that already has one.
 */
class SeedChartOfAccounts extends Command
{
    protected $signature = 'accounting:seed-chart-of-accounts
        {business? : A single tenant/business id}
        {--all : Backfill every existing business}';

    protected $description = 'Seed the standard chart of accounts for a business (or every business)';

    public function handle(ChartOfAccountsSeeder $seeder): int
    {
        $businessId = $this->argument('business');
        $all = (bool) $this->option('all');

        if (! $businessId && ! $all) {
            $this->error('Pass a business id or --all.');

            return self::FAILURE;
        }

        $tenants = $all ? Tenant::all() : Tenant::where('id', $businessId)->get();

        if ($tenants->isEmpty()) {
            $this->error('No matching business found.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $seeder->seedForBusiness($tenant->id);
            $this->info("Seeded chart of accounts for {$tenant->id}.");
        }

        return self::SUCCESS;
    }
}
