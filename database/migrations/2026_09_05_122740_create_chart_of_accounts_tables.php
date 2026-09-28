<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 11a (General Ledger foundation) — a three-level chart of
     * accounts: account_categories (Assets/Liabilities/Equity/Revenue/Cost of
     * Sales/Expenses, each flagged with its normal debit/credit side and
     * which financial statement it belongs to) -> account_sub_categories
     * (grouping only) -> gl_accounts (the leaf, postable account). Only a
     * gl_account can ever receive a journal line — categories/sub-categories
     * exist purely for report grouping and the is_debit_normal sign flip
     * every report depends on. Modeled on Blackrock ERP's finance module,
     * scaled down for a single-business chart rather than a multi-company one.
     */
    public function up(): void
    {
        Schema::create('account_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('name');
            $table->unsignedInteger('code')->nullable();
            // true = balance grows with debits (Assets, Cost of Sales,
            // Expenses); false = balance grows with credits (Liabilities,
            // Equity, Revenue). Every report flips its sign off this flag.
            $table->boolean('is_debit_normal');
            $table->enum('statement_type', ['balance_sheet', 'income_statement']);
            $table->unsignedInteger('reporting_order')->default(0);
            // Seeded categories are protected from deletion; an owner can
            // still add/rename accounts underneath them.
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('account_sub_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('account_category_id')->index();
            $table->string('name');
            $table->unsignedInteger('reporting_order')->default(0);
            $table->timestamps();
        });

        Schema::create('gl_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('code');
            $table->string('name');
            $table->uuid('account_category_id')->index();
            $table->uuid('account_sub_category_id')->nullable()->index();
            $table->boolean('allow_direct_posting')->default(true);
            // Which control account this is, if any — lets the debtors/
            // creditors sub-ledger (Phase 11c) resolve "the Accounts
            // Receivable account" without hardcoding a code. Null for an
            // ordinary posting account.
            $table->enum('control_type', ['receivable', 'payable', 'inventory'])->nullable();
            // Enforced at post time (JournalService) — e.g. a cash/bank
            // account that should never go negative.
            $table->boolean('must_be_positive')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['business_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gl_accounts');
        Schema::dropIfExists('account_sub_categories');
        Schema::dropIfExists('account_categories');
    }
};
