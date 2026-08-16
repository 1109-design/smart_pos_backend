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
        Schema::table('products', function (Blueprint $table) {
            // Refundable deposit for itemType == 'container' rows. Kept
            // separate from `price` so generic revenue reports never sweep
            // deposits in as revenue.
            $table->decimal('deposit_amount', 15, 4)->nullable()->after('cost_price');
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Container deposits collected on this sale — included in
            // `total` for till reconciliation, but deliberately excluded
            // from `tax_total`/`transaction_items` so ZIMRA fiscalisation
            // and revenue reports never see it.
            $table->decimal('deposit_total', 15, 4)->default(0)->after('discount_total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('deposit_amount');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('deposit_total');
        });
    }
};
