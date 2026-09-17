<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Which named bank account funded the purchase — see
            // BankAccountService. Null falls back to the 'default_bank' role
            // mapping.
            $table->uuid('bank_account_id')->nullable();
            // Which named bank account disposal proceeds were deposited
            // into — set only at disposal time, independent of the
            // acquisition funding account above.
            $table->uuid('disposal_bank_account_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['bank_account_id', 'disposal_bank_account_id']);
        });
    }
};
