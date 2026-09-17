<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matches the POS `payments.exchange_rate_used` column this table was
     * always missing relative to — `currency_code`/`base_equivalent` alone
     * can't reconstruct the original tendered amount or the rate applied,
     * which InvoicePaymentPostingService needs to populate a GL line's
     * foreign_debit/foreign_credit for a non-base-currency payment.
     */
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->decimal('exchange_rate_used', 20, 8)->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropColumn('exchange_rate_used');
        });
    }
};
