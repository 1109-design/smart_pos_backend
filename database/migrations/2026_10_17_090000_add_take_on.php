<?php

use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — take-on (opening) balances, captured and posted on a device.
 *
 * - take_on_batches: one imported file or manual entry of one type (gl,
 *   customers, deposits, suppliers, stock, bank, assets, vat), with its
 *   status (draft → validated → pending_approval → posting → posted, or
 *   reversed), who uploaded and approved it, its totals and the original
 *   file. Synced from the device; the server stores and relays it.
 * - take_on_rows: the batch's rows as the device validated them, and the
 *   journal each one posted.
 * - businesses.take_on_finalised_at / _by_user_id: once set, take-on is
 *   closed on every device and no more take-on journals are accepted.
 * - 1170 Supplier Prepayments and 3025 Take-on Clearing for every
 *   business that already has a chart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('take_on_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('type', 16);
            $table->string('status', 24)->default('draft');
            $table->string('source', 16)->default('import');
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->json('totals')->nullable();
            $table->uuid('approval_request_id')->nullable();
            $table->uuid('uploaded_by_user_id')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('posted_by_device_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->uuid('reversed_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('take_on_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->index();
            $table->string('business_id')->index();
            $table->unsignedInteger('row_no');
            $table->json('data');
            $table->json('errors')->nullable();
            $table->string('status', 16)->default('valid');
            $table->uuid('journal_id')->nullable();
            $table->string('ref_id')->nullable();
            $table->timestamps();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->timestamp('take_on_finalised_at')->nullable()->after('accounting_period_end_guard_days');
            $table->uuid('take_on_finalised_by_user_id')->nullable()->after('take_on_finalised_at');
        });

        $seeder = app(ChartOfAccountsSeeder::class);
        foreach (DB::table('account_categories')->distinct()->pluck('business_id') as $businessId) {
            foreach (ChartOfAccountsSeeder::PHASE3_ACCOUNTS as [$category, $subCategory, $account]) {
                $seeder->ensureAccount($businessId, $category, $subCategory, $account);
            }
        }
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['take_on_finalised_at', 'take_on_finalised_by_user_id']);
        });
        Schema::dropIfExists('take_on_rows');
        Schema::dropIfExists('take_on_batches');
        // The accounts are left in place — journals may already use them.
    }
};
