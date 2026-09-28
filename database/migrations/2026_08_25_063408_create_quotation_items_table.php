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
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('quotation_id')->index();
            $table->uuid('product_id');
            $table->string('product_name'); // snapshot at quote time
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->uuid('tax_rate_id')->nullable();
            $table->decimal('line_total', 15, 4)->default(0);
            // Running total of how much of this line has been invoiced so
            // far — lets progressive invoicing against one accepted
            // quotation avoid over-invoicing a line.
            $table->decimal('invoiced_quantity', 15, 4)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
    }
};
