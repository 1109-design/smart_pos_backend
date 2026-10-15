<?php

use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — payables (Flutter v119).
 *
 * - payment_vouchers: supplier / expense / prepayment / WHT settlement
 *   vouchers, approved by someone other than whoever raised them; lines,
 *   payments (split across banks) and history travel as JSON.
 * - supplier_payments.is_prepayment: paid ahead of an invoice (Dr Supplier
 *   Prepayments), cleared to AP when allocated.
 * - supplier_invoices.replaces_invoice_id: a revised invoice's link to the
 *   one it superseded (which was reversed, never edited).
 * - 2070 Payment Vouchers Payable for every chart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('voucher_number');
            $table->string('voucher_type', 24);
            $table->uuid('supplier_id')->nullable()->index();
            $table->string('payee')->default('');
            $table->date('voucher_date');
            $table->text('description')->nullable();
            $table->decimal('total', 15, 4)->default(0);
            $table->decimal('paid_amount', 15, 4)->default(0);
            $table->string('status', 24)->default('draft');
            $table->text('close_reason')->nullable();
            $table->json('lines');
            $table->json('payments');
            $table->json('history');
            $table->uuid('approval_request_id')->nullable();
            $table->uuid('accrual_journal_id')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'voucher_number']);
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->boolean('is_prepayment')->default(false);
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->uuid('replaces_invoice_id')->nullable();
        });

        $seeder = app(ChartOfAccountsSeeder::class);
        foreach (DB::table('account_categories')->distinct()->pluck('business_id') as $businessId) {
            foreach (ChartOfAccountsSeeder::PHASE8_ACCOUNTS as [$category, $subCategory, $account]) {
                $seeder->ensureAccount($businessId, $category, $subCategory, $account);
            }
        }
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn('replaces_invoice_id');
        });
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropColumn('is_prepayment');
        });
        Schema::dropIfExists('payment_vouchers');
    }
};
