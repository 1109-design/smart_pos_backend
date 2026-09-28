<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Presentment currency for a quote — subtotal/discount_total/tax_total/
     * total stay in the business's base currency; these are sidecar fields
     * so the quote can be displayed/printed converted at the day's rate.
     * Same naming/typing convention as invoice_payments' currency_code/
     * exchange_rate_used.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->string('currency_code', 10)->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'exchange_rate']);
        });
    }
};
