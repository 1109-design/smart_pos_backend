<?php

use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9 — multi-currency. Month-end revaluation posts to 4025 Unrealised
 * FX Gain / 6085 Unrealised FX Loss, kept apart from realised 4020 / 6080.
 *
 * No other schema change: rates already mean "units of foreign per 1
 * base". Foreign supplier payments once posted at amount × rate are put
 * right on the device (reversed and reposted at amount ÷ rate), which
 * pushes the corrected base_equivalent here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seeder = app(ChartOfAccountsSeeder::class);
        foreach (DB::table('account_categories')->distinct()->pluck('business_id') as $businessId) {
            foreach (ChartOfAccountsSeeder::PHASE9_ACCOUNTS as [$category, $subCategory, $account]) {
                $seeder->ensureAccount($businessId, $category, $subCategory, $account);
            }
        }
    }

    public function down(): void
    {
        // Accounts may already carry postings; they stay.
    }
};
