<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A product's own product_stock rows always make it sellable at that
     * location — this table only adds *extra* locations on top, where
     * staff may offer the product even though its stock physically lives
     * elsewhere. Composite-key join, no own id/timestamps — mirrors
     * product_tax_rates exactly.
     */
    public function up(): void
    {
        Schema::create('product_sellable_locations', function (Blueprint $table) {
            $table->uuid('product_id');
            $table->uuid('location_id');
            $table->primary(['product_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sellable_locations');
    }
};
