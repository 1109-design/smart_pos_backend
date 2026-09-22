<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            // Multi-currency basis (mirrors invoice_payments): tender amount
            // stays in `amount`/`currency_code`, GL + allocations run in
            // base. Nullable/defaulted so pre-existing USD rows need no
            // backfill — amount IS the base amount when rate is 1.
            $table->decimal('exchange_rate_used', 18, 6)->default(1)->after('currency_code');
            $table->decimal('base_equivalent', 18, 2)->nullable()->after('exchange_rate_used');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropColumn(['exchange_rate_used', 'base_equivalent']);
        });
    }
};
