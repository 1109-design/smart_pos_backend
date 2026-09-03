<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Null = use the product's business-wide default (Product.price /
     * Product.low_stock_threshold). Set = this location overrides it. A
     * warehouse holding 500 units and a small branch holding 20 need
     * different reorder points, and a branch in a different area may need a
     * different price — neither was expressible before this migration.
     */
    public function up(): void
    {
        Schema::table('product_stock', function (Blueprint $table) {
            $table->decimal('low_stock_threshold', 15, 4)->nullable();
            $table->decimal('price_override', 15, 4)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_stock', function (Blueprint $table) {
            $table->dropColumn(['low_stock_threshold', 'price_override']);
        });
    }
};
