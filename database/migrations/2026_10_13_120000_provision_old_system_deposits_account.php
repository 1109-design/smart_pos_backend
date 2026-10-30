<?php

use App\Models\Accounting\AccountCategory;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Old-System Customer Deposits (2046) — deposits customers paid in the
 * previous POS system, used up by the till's 'legacy_deposit' tender when
 * they collect (see SalePostingService). New charts get it from
 * ChartOfAccountsSeeder::CHART; this adds it to every chart seeded before it
 * existed. ensureAccount() publishes the new row, so tills pull it on their
 * next sync.
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
                ChartOfAccountsSeeder::OLD_SYSTEM_DEPOSITS,
            ));
    }

    public function down(): void
    {
        // Left in place — journals may already post against it.
    }
};
