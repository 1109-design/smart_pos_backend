<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scoped deliberately to what a hardware chain needs most commonly: pack/
     * box/case selling at a fixed conversion, and quantity-break pricing.
     * Explicitly excludes continuous/cut-to-length stock (timber, pipe, cable
     * sold by the metre from a stocked length with remnant tracking) — that's
     * a materially larger feature (a different stock unit than the sale unit
     * at the ledger level, plus remnant/waste tracking) and should be scoped
     * separately if wanted.
     */
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id')->index();
            $table->string('unit_name');
            // How many of the product's base unit (what stock_movements/
            // product_stock already track in) one of this unit equals — e.g.
            // "box" with conversion_factor=100 means 1 box = 100 base units.
            $table->decimal('conversion_factor', 15, 4)->default(1);
            $table->boolean('is_base_unit')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'unit_name']);
        });

        Schema::create('product_price_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id')->index();
            // Quantities are always in the product's base unit — a unit
            // conversion multiplier is applied on top of the resolved tier
            // price, not folded into the threshold itself.
            $table->decimal('min_qty', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->timestamps();

            $table->unique(['product_id', 'min_qty']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_price_tiers');
        Schema::dropIfExists('product_units');
    }
};
