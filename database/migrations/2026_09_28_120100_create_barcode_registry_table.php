<?php

use App\Services\BarcodeRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per barcode in use, whichever table holds it — the UNIQUE
     * index below is what actually enforces "every barcode is unique within
     * a business" across products, product_barcodes and product_variants.
     * See App\Services\BarcodeRegistry.
     */
    public function up(): void
    {
        Schema::create('barcode_registry', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id');
            $table->string('barcode', 100);
            // product | product_barcode | product_variant
            $table->string('owner_type', 20);
            $table->uuid('owner_id');
            $table->timestamps();

            $table->unique(['business_id', 'barcode']);
            $table->unique(['owner_type', 'owner_id']);
        });

        $cleared = app(BarcodeRegistry::class)->rebuild();
        if ($cleared > 0) {
            Log::warning("barcode_registry: cleared {$cleared} duplicate barcode(s) held by more than one item; the oldest holder kept each.");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('barcode_registry');
    }
};
