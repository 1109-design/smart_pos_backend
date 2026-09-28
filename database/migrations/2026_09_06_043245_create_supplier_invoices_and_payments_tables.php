<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purchasing & Cash Vault Blueprint, part B.
     *
     * supplier_invoices: recording the supplier's actual bill against a GRV
     * clears the GRN Suspense that GRV posted (part A) and creates the real
     * Accounts Payable liability — one invoice per GRV for now (unique on
     * grv_id); a business billed across multiple deliveries in one invoice
     * is a future enhancement, not this pass.
     *
     * supplier_payments: a simple running-balance payment against a
     * supplier, matching the same "one balance, FIFO-aged" simplification
     * Phase 11c's PartyLedgerService already uses for debtors — not tied to
     * a specific invoice.
     */
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('supplier_id')->index();
            $table->uuid('grv_id')->unique();
            $table->string('invoice_number');
            $table->date('invoice_date');
            $table->decimal('amount', 15, 4);
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('supplier_id')->index();
            $table->decimal('amount', 15, 4);
            $table->string('currency_code', 10)->default('USD');
            $table->date('payment_date');
            $table->string('method')->default('cash');
            $table->string('reference')->nullable();
            $table->uuid('recorded_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_invoices');
    }
};
