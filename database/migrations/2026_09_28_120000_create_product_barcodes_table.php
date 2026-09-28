<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additional barcodes per product, on top of products.barcode (which stays
     * the primary one). Uniqueness across products.barcode, product_barcodes
     * and product_variants.barcode within a business is enforced by the
     * writers (the till's barcode_registry.dart, BackOffice validation) rather
     * than a DB constraint — two offline tills can race the same code and a
     * hard unique index would wedge the sync push instead of surfacing it.
     */
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id')->index();
            $table->string('barcode', 100)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};
