<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * See the identical column on `payments` — same meaning, same
     * fallback-to-1010-when-null behavior. Only meaningful for a
     * `type='repayment'` row (a credit-sale row never involves a tender).
     */
    public function up(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->uuid('bank_account_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropColumn('bank_account_id');
        });
    }
};
