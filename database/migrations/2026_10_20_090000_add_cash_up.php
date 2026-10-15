<?php

use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — till, cash and receipting (Flutter v118).
 *
 * - z_reports: one per closed shift — its figures (tenders, sales, returns,
 *   voids, discounts, VAT, expected vs counted per currency, variance),
 *   fixed once made; the cash-up's approval and printing are recorded.
 * - businesses.cash_up_variance_threshold: over / short within this posts
 *   at close; more needs a supervisor (not the cashier).
 * - 6067 Cash Over/Short and 2060 Layby Deposits for every chart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('z_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('location_id')->nullable();
            $table->uuid('till_id')->nullable();
            $table->uuid('shift_id')->unique();
            $table->uuid('cashier_id');
            $table->string('report_number');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('data');
            $table->decimal('variance', 15, 4)->default(0);
            $table->string('status', 24)->default('approved');
            $table->uuid('approval_request_id')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('variance_journal_id')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->decimal('cash_up_variance_threshold', 15, 4)->default(0)->after('take_on_finalised_by_user_id');
        });

        $seeder = app(ChartOfAccountsSeeder::class);
        foreach (DB::table('account_categories')->distinct()->pluck('business_id') as $businessId) {
            foreach (ChartOfAccountsSeeder::PHASE7_ACCOUNTS as [$category, $subCategory, $account]) {
                $seeder->ensureAccount($businessId, $category, $subCategory, $account);
            }
        }
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('cash_up_variance_threshold');
        });
        Schema::dropIfExists('z_reports');
    }
};
