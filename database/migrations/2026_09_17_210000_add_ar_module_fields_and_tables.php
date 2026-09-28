<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts Receivable (AR) module migration.
     * Aligns with the Flutter-First offline database schema for SmartPOS.
     */
    public function up(): void
    {
        // 1. Extend customers master
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_code')->nullable()->after('id');
            $table->string('trading_name')->nullable()->after('name');
            $table->string('customer_type')->default('individual'); // individual, corporate, government
            $table->string('contact_person')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('currency_code', 10)->default('USD');
            $table->unsignedInteger('payment_terms_days')->default(30);
            $table->string('credit_status')->default('active'); // active, warning, blocked, credit_suspended, cash_only
            $table->string('credit_control_policy')->default('block'); // block, warn, require_approval
            $table->uuid('sales_person_id')->nullable();
            $table->uuid('location_id')->nullable();
            $table->string('customer_category')->default('standard');
            $table->uuid('price_list_id')->nullable();
            $table->uuid('discount_rule_id')->nullable();
            $table->uuid('control_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
        });

        // 2. Extend invoices
        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('sales_order_id')->nullable()->index();
            $table->uuid('delivery_note_id')->nullable()->index();
            $table->uuid('sales_person_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->uuid('posted_by_user_id')->nullable();
            $table->boolean('is_gl_posted')->default(false);
            $table->string('sync_status')->default('synced');
        });

        // 3. Extend credit_notes
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->string('status')->default('draft'); // draft, approved, posted, rejected
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->boolean('is_gl_posted')->default(false);
            $table->decimal('unallocated_amount', 15, 4)->default(0);
            $table->uuid('journal_header_id')->nullable();
            $table->string('sync_status')->default('synced');
        });

        // 4. Sales Orders
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('location_id')->nullable()->index();
            $table->string('order_number')->index();
            $table->uuid('quotation_id')->nullable()->index();
            $table->uuid('customer_id')->index();
            $table->uuid('sales_person_id')->nullable();
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('discount_total', 15, 4)->default(0);
            $table->decimal('tax_total', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->decimal('deposit_required', 15, 4)->default(0);
            $table->decimal('deposit_paid', 15, 4)->default(0);
            $table->string('status')->default('draft'); // draft, confirmed, approved, partially_delivered, fully_delivered, invoiced, cancelled
            $table->string('delivery_status')->default('pending'); // pending, partial, complete
            $table->string('invoicing_status')->default('pending'); // pending, partial, complete
            $table->text('terms_and_conditions')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();
        });

        // 5. Sales Order Items
        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sales_order_id')->index();
            $table->uuid('product_id')->nullable()->index();
            $table->string('description');
            $table->decimal('ordered_quantity', 15, 4);
            $table->decimal('delivered_quantity', 15, 4)->default(0);
            $table->decimal('invoiced_quantity', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount_pct', 8, 4)->default(0);
            $table->uuid('tax_rate_id')->nullable();
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('line_total', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 6. Delivery Notes
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('location_id')->nullable()->index();
            $table->string('delivery_number')->index();
            $table->uuid('sales_order_id')->nullable()->index();
            $table->uuid('customer_id')->index();
            $table->date('delivery_date');
            $table->string('status')->default('pending'); // pending, dispatched, delivered, partially_invoiced, invoiced, cancelled
            $table->uuid('dispatched_by_user_id')->nullable();
            $table->string('received_by_name')->nullable();
            $table->string('received_by_signature_path')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('tracking_reference')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();
        });

        // 7. Delivery Note Items
        Schema::create('delivery_note_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('delivery_note_id')->index();
            $table->uuid('sales_order_item_id')->nullable()->index();
            $table->uuid('product_id')->nullable()->index();
            $table->string('description');
            $table->decimal('dispatched_quantity', 15, 4);
            $table->decimal('accepted_quantity', 15, 4);
            $table->decimal('rejected_quantity', 15, 4)->default(0);
            $table->string('rejection_reason')->nullable();
            $table->decimal('invoiced_quantity', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 8. Customer Receipts
        Schema::create('customer_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('receipt_number')->index();
            $table->uuid('customer_id')->index();
            $table->date('receipt_date');
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('amount', 15, 4);
            $table->decimal('base_amount', 15, 4);
            $table->decimal('unallocated_amount', 15, 4)->default(0);
            $table->string('payment_method'); // cash, bank_transfer, card, cheque, mobile_money
            $table->uuid('bank_account_id')->nullable()->index();
            $table->string('reference')->nullable();
            $table->boolean('is_advance')->default(false);
            $table->text('notes')->nullable();
            $table->uuid('journal_header_id')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->string('sync_status')->default('synced');
            $table->timestamps();
        });

        // 9. Customer Receipt Allocations
        Schema::create('customer_receipt_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('customer_receipt_id')->index();
            $table->uuid('invoice_id')->index();
            $table->decimal('allocated_amount', 15, 4);
            $table->decimal('tender_amount', 15, 4)->nullable();
            $table->decimal('exchange_rate_used', 15, 6)->default(1);
            $table->timestamp('allocated_at');
            $table->uuid('allocated_by_user_id')->nullable();
            $table->timestamps();
        });

        // 10. Customer Debit Notes
        Schema::create('customer_debit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('customer_id')->index();
            $table->uuid('invoice_id')->nullable()->index();
            $table->string('debit_note_number')->index();
            $table->date('note_date');
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('tax_total', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->string('reason')->nullable();
            $table->string('status')->default('draft'); // draft, posted
            $table->boolean('is_gl_posted')->default(false);
            $table->uuid('journal_header_id')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();
        });

        // 11. Customer Debit Note Items
        Schema::create('customer_debit_note_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_debit_note_id')->index();
            $table->uuid('product_id')->nullable()->index();
            $table->string('description');
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price', 15, 4);
            $table->uuid('tax_rate_id')->nullable();
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('line_total', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 12. Customer Adjustments
        Schema::create('customer_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('customer_id')->index();
            $table->string('adjustment_number')->index();
            $table->date('adjustment_date');
            $table->string('adjustment_type'); // debit, credit
            $table->decimal('amount', 15, 4);
            $table->string('reason_code'); // billing_correction, rebate, settlement_discount, bad_debt_recovery, exchange_gain_loss, other
            $table->string('description');
            $table->string('reference')->nullable();
            $table->uuid('gl_offset_account_id')->nullable();
            $table->string('status')->default('draft'); // draft, approved, rejected, posted
            $table->boolean('is_gl_posted')->default(false);
            $table->uuid('journal_header_id')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();
        });

        // 13. Customer Write-Offs
        Schema::create('customer_write_offs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('customer_id')->index();
            $table->string('write_off_number')->index();
            $table->date('write_off_date');
            $table->decimal('amount', 15, 4);
            $table->uuid('invoice_id')->nullable()->index();
            $table->string('reason'); // insolvency, untraceable, dispute_settlement, small_balance_cleanup, legal_ruling
            $table->string('status')->default('pending_approval'); // pending_approval, approved, rejected, posted
            $table->boolean('is_gl_posted')->default(false);
            $table->uuid('journal_header_id')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();
        });

        // 14. AR Collection Activities
        Schema::create('ar_collection_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('customer_id')->index();
            $table->string('activity_type'); // phone_call, email, demand_letter, visit, statement_sent, payment_reminder, sms
            $table->timestamp('activity_date');
            $table->string('contact_person')->nullable();
            $table->string('phone_or_email')->nullable();
            $table->text('notes');
            $table->string('outcome')->nullable(); // promised_to_pay, disputed, refused, left_message, no_answer, follow_up_needed
            $table->date('next_action_date')->nullable();
            $table->uuid('recorded_by_user_id')->nullable();
            $table->timestamps();
        });

        // 15. AR Promises To Pay
        Schema::create('ar_promises_to_pay', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('customer_id')->index();
            $table->date('promise_date');
            $table->date('promised_payment_date');
            $table->decimal('promised_amount', 15, 4);
            $table->decimal('paid_amount', 15, 4)->default(0);
            $table->string('status')->default('pending'); // pending, kept, partially_kept, broken, cancelled
            $table->text('notes')->nullable();
            $table->uuid('collection_activity_id')->nullable()->index();
            $table->uuid('recorded_by_user_id')->nullable();
            $table->timestamps();
        });

        // 16. AR Disputes
        Schema::create('ar_disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('customer_id')->index();
            $table->uuid('invoice_id')->index();
            $table->string('dispute_number')->index();
            $table->date('dispute_date');
            $table->decimal('disputed_amount', 15, 4);
            $table->string('reason_category'); // pricing_error, damaged_goods, missing_items, quality_defect, terms_disagreement, unrecognized_order
            $table->text('description');
            $table->string('status')->default('open'); // open, under_investigation, resolved_credit_note, resolved_rejected, resolved_settlement
            $table->text('resolution_notes')->nullable();
            $table->uuid('credit_note_id')->nullable()->index();
            $table->uuid('assigned_to_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();
        });

        // 17. Customer Reconciliations
        Schema::create('customer_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('customer_id')->index();
            $table->date('reconciliation_date');
            $table->date('statement_cutoff_date');
            $table->decimal('customer_statement_balance', 15, 4);
            $table->decimal('ledger_balance', 15, 4);
            $table->decimal('variance', 15, 4);
            $table->string('status')->default('in_progress'); // in_progress, reconciled, disputed
            $table->text('notes')->nullable();
            $table->uuid('reconciled_by_user_id')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
        });

        // 18. Customer Reconciliation Items
        Schema::create('customer_reconciliation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_reconciliation_id')->index();
            $table->string('item_type'); // missing_in_customer_statement, missing_in_ledger, amount_mismatch, timing_difference
            $table->string('reference_number')->nullable();
            $table->date('item_date')->nullable();
            $table->decimal('ledger_amount', 15, 4)->default(0);
            $table->decimal('statement_amount', 15, 4)->default(0);
            $table->decimal('difference', 15, 4)->default(0);
            $table->text('explanation')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->string('resolution_action')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_reconciliation_items');
        Schema::dropIfExists('customer_reconciliations');
        Schema::dropIfExists('ar_disputes');
        Schema::dropIfExists('ar_promises_to_pay');
        Schema::dropIfExists('ar_collection_activities');
        Schema::dropIfExists('customer_write_offs');
        Schema::dropIfExists('customer_adjustments');
        Schema::dropIfExists('customer_debit_note_items');
        Schema::dropIfExists('customer_debit_notes');
        Schema::dropIfExists('customer_receipt_allocations');
        Schema::dropIfExists('customer_receipts');
        Schema::dropIfExists('delivery_note_items');
        Schema::dropIfExists('delivery_notes');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'approved_by_user_id',
                'approved_at',
                'posted_at',
                'is_gl_posted',
                'unallocated_amount',
                'journal_header_id',
                'sync_status',
            ]);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'sales_order_id',
                'delivery_note_id',
                'sales_person_id',
                'posted_at',
                'posted_by_user_id',
                'is_gl_posted',
                'sync_status',
            ]);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'customer_code',
                'trading_name',
                'customer_type',
                'contact_person',
                'tax_number',
                'currency_code',
                'payment_terms_days',
                'credit_status',
                'credit_control_policy',
                'sales_person_id',
                'location_id',
                'customer_category',
                'price_list_id',
                'discount_rule_id',
                'control_account_id',
                'is_active',
                'notes',
            ]);
        });
    }
};
