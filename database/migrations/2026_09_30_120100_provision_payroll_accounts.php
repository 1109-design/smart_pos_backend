<?php

use App\Models\Accounting\AccountCategory;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Payroll GL accounts (1160, 2050–2056, 6025) — see PayRunPostingService.
 * New charts get them from ChartOfAccountsSeeder::CHART; this adds them to
 * every chart seeded before they existed. ensureAccount() publishes each
 * new row, so tills pull it on their next sync (the till resolves payroll
 * roles by these codes and skips posting until they arrive).
 */
return new class extends Migration
{
    public function up(): void
    {
        $seeder = app(ChartOfAccountsSeeder::class);

        AccountCategory::query()->distinct()->pluck('business_id')
            ->each(function (string $businessId) use ($seeder) {
                foreach (ChartOfAccountsSeeder::PAYROLL_ACCOUNTS as [$category, $subCategory, $account]) {
                    $seeder->ensureAccount($businessId, $category, $subCategory, $account);
                }
            });
    }

    public function down(): void
    {
        // Left in place — journals may already post against them.
    }
};
