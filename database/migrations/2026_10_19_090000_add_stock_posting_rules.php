<?php

use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — stock posting rules (Flutter v117): which accounts a stock
 * movement type posts to, optionally per location and / or product
 * category; the most specific active rule wins on the device. Also 1210
 * Stock in Transit for every business with a chart (transfers post
 * through it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_posting_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('movement_type', 32);
            $table->uuid('location_id')->nullable();
            $table->uuid('product_category_id')->nullable();
            $table->uuid('debit_account_id');
            $table->uuid('credit_account_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $seeder = app(ChartOfAccountsSeeder::class);
        foreach (DB::table('account_categories')->distinct()->pluck('business_id') as $businessId) {
            foreach (ChartOfAccountsSeeder::PHASE6_ACCOUNTS as [$category, $subCategory, $account]) {
                $seeder->ensureAccount($businessId, $category, $subCategory, $account);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_posting_rules');
    }
};
