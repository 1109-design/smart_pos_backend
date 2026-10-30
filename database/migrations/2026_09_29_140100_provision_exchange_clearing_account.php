<?php

use App\Models\Accounting\AccountCategory;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Exchange Clearing (2045) — where the value of goods a customer hands back
 * sits for the instant between the return and the replacement sale in an
 * exchange (the till's 'exchange_credit' tender; see SalePostingService).
 * New charts get it from ChartOfAccountsSeeder::CHART; this adds it to every
 * chart seeded before it existed. ensureAccount() publishes the new row, so
 * tills pull it on their next sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seeder = app(ChartOfAccountsSeeder::class);

        AccountCategory::query()->distinct()->pluck('business_id')
            ->each(fn (string $businessId) => $seeder->ensureAccount(
                $businessId,
                'Liabilities',
                'Current Liabilities',
                ChartOfAccountsSeeder::EXCHANGE_CLEARING,
            ));
    }

    public function down(): void
    {
        // Left in place — journals may already post against it.
    }
};
