<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts Payable module — see the Flutter-first AP architecture plan.
     * Extends the supplier master with real AP fields, and replaces the
     * previous one-invoice-per-GRV `supplier_invoices` row (no line items,
     * no due date, no currency, no non-PO support — see
     * 2026_09_06_043245_create_supplier_invoices_and_payments_tables.php)
     * with a proper multi-line AP invoice. `grv_id` stays (nullable, no
     * longer unique) purely so the existing BackOffice-only
     * SupplierInvoiceService::recordInvoice() legacy flow keeps working for
     * a business not yet cut over to client-side posting — see that
     * service's own new `postsFromClientFor()` guard.
     */
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('supplier_code')->nullable()->after('id');
            $table->string('trading_name')->nullable()->after('name');
            $table->string('currency_code', 10)->nullable();
            $table->unsignedInteger('payment_terms_days')->default(30);
            $table->decimal('credit_limit', 15, 4)->nullable();
            $table->string('category')->nullable();
            $table->string('tax_status')->default('standard');
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_branch')->nullable();
            $table->uuid('control_account_id')->nullable();
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropUnique(['grv_id']);
            $table->uuid('grv_id')->nullable()->change();
            $table->uuid('purchase_order_id')->nullable()->index()->after('grv_id');
            $table->date('due_date')->nullable();
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('discount_total', 15, 4)->default(0);
            $table->decimal('tax_total', 15, 4)->default(0);
            $table->decimal('withholding_tax_total', 15, 4)->default(0);
            $table->decimal('other_charges_total', 15, 4)->default(0);
            $table->string('status')->default('draft');
            $table->string('match_status')->default('not_applicable');
            $table->text('description')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
        });

        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_invoice_id')->index();
            $table->uuid('purchase_order_item_id')->nullable();
            $table->uuid('grv_item_id')->nullable();
            $table->uuid('product_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('discount_pct', 8, 4)->default(0);
            $table->uuid('tax_rate_id')->nullable();
            $table->uuid('gl_account_id')->nullable();
            $table->decimal('line_total', 15, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('supplier_payment_id')->index();
            $table->uuid('supplier_invoice_id')->index();
            $table->decimal('amount', 15, 4);
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_credit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('supplier_id')->index();
            $table->uuid('supplier_invoice_id')->nullable()->index();
            $table->string('note_number');
            $table->string('note_type')->default('credit'); // credit | debit
            $table->date('note_date');
            $table->string('reason')->nullable();
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('tax_total', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->string('status')->default('draft'); // draft | posted
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_credit_note_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_credit_note_id')->index();
            $table->uuid('supplier_invoice_line_id')->nullable();
            $table->uuid('product_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_cost', 15, 4);
            $table->uuid('gl_account_id')->nullable();
            $table->decimal('line_total', 15, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('ap_tolerance_settings', function (Blueprint $table) {
            $table->string('business_id')->primary();
            $table->decimal('quantity_tolerance_pct', 8, 4)->default(2.0);
            $table->decimal('price_tolerance_pct', 8, 4)->default(1.0);
            $table->decimal('amount_tolerance', 15, 4)->default(50.0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ap_tolerance_settings');
        Schema::dropIfExists('supplier_credit_note_lines');
        Schema::dropIfExists('supplier_credit_notes');
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_invoice_lines');

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_order_id', 'due_date', 'currency_code', 'exchange_rate',
                'subtotal', 'discount_total', 'tax_total', 'withholding_tax_total',
                'other_charges_total', 'status', 'match_status', 'description',
                'approved_by_user_id', 'approved_at', 'posted_at',
            ]);
            $table->uuid('grv_id')->nullable(false)->change();
            $table->unique('grv_id');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_code', 'trading_name', 'currency_code', 'payment_terms_days',
                'credit_limit', 'category', 'tax_status', 'bank_name',
                'bank_account_number', 'bank_branch', 'control_account_id',
            ]);
        });
    }
};
