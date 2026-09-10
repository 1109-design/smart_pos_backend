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
            // Not every product/service is subject to tax — defaults to true
            // so every existing item keeps behaving exactly as it does today.
            // A false value zero-rates the item outright, overriding whatever
            // tax rate(s) are assigned via product_tax_rates.
            $table->boolean('is_taxable')->default(true)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_taxable');
        });
    }
};
