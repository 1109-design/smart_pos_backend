<?php

use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 4030 Stock Gains (Other Income) for every existing chart: stock counted,
 * adjusted or revalued up posts here instead of crediting 6050 Stock Loss /
 * Write-offs. New businesses get it from the seeded chart.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seeder = app(ChartOfAccountsSeeder::class);
        foreach (DB::table('account_categories')->distinct()->pluck('business_id') as $businessId) {
            foreach (ChartOfAccountsSeeder::STOCK_GAIN_ACCOUNTS as [$category, $subCategory, $account]) {
                $seeder->ensureAccount($businessId, $category, $subCategory, $account);
            }
        }
    }

    public function down(): void
    {
        // The account may already carry postings; it stays.
    }
};
