<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Presentment currency for an invoice — subtotal/discount_total/
     * tax_total/total/amount_paid stay in the business's base currency;
     * these are sidecar fields so the invoice can be displayed/printed
     * converted at the day's rate. Distinct from invoice_payments'
     * currency_code/exchange_rate_used, which record what a customer
     * actually tendered for one payment (may differ from this).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('currency_code', 10)->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'exchange_rate']);
        });
    }
};
