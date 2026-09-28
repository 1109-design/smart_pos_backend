<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A business's own named bank accounts — selectable wherever a
     * "bank transfer" tender is recorded, each backed by its own
     * `gl_account_id` (see BankAccountService::create(), which provisions
     * it via ChartOfAccountsSeeder::ensureAccount() under a new "Bank
     * Accounts" sub-category of Assets). Deliberately separate from the
     * pre-existing `businesses.bank_accounts_json` — that field is purely
     * cosmetic (printed on invoice PDFs), this one is ledger-linked.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('name');
            $table->string('account_number')->nullable();
            $table->string('branch')->nullable();
            $table->string('currency_code', 10)->default('USD');
            $table->uuid('gl_account_id')->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
