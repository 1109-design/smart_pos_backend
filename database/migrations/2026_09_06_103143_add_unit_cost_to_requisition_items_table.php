<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRJ·04's cost build-up needs the cost basis at the moment stock left
     * the warehouse, not whatever `products.cost_price` happens to be when
     * the report is later viewed — a snapshot here avoids re-deriving it
     * from `stock_movements` on every report render.
     */
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 4)->nullable()->after('quantity_issued');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
