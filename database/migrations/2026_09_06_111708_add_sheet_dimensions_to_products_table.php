<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GLS·01 — the default whole-sheet size for a `item_type = 'sheet'`
     * product, used when receiving stock (each unit received creates one
     * `sheet_lots` row of this size). `products.price` is reused as the
     * per-unit-area rate for sheet products rather than adding a separate
     * column — selling a whole, uncut sheet is just "cut the full
     * original_width × original_height", so one price field already covers
     * both whole-sheet and custom-cut sales.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('sheet_width', 15, 4)->nullable()->after('item_type');
            $table->decimal('sheet_height', 15, 4)->nullable()->after('sheet_width');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sheet_width', 'sheet_height']);
        });
    }
};
