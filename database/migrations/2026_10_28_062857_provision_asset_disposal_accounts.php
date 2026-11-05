<?php

use App\Models\Accounting\AccountCategory;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Supplier Refunds Due (1165) and Insurance Claims Receivable (1170) — see
 * ChartOfAccountsSeeder::ASSET_DISPOSAL_ACCOUNTS. New charts get them from
 * ChartOfAccountsSeeder::CHART; this adds them to every chart seeded before
 * they existed and publishes them so tills pull them on their next sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seeder = app(ChartOfAccountsSeeder::class);

        AccountCategory::query()->distinct()->pluck('business_id')
            ->each(function (string $businessId) use ($seeder) {
                foreach (ChartOfAccountsSeeder::ASSET_DISPOSAL_ACCOUNTS as [$category, $subCategory, $account]) {
                    $seeder->ensureAccount($businessId, $category, $subCategory, $account);
                }
            });
    }

    public function down(): void
    {
        // Left in place — journals may already post against them.
    }
};
