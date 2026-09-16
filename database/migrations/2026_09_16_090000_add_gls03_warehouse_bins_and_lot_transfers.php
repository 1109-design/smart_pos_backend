<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GLS·03 — structured warehouse bins (Zone/Rack/Bay/Position), lot-aware
     * stock transfers, and reservable dimensional-material quotation lines.
     * See the Flutter side's app_database.dart migration `from < 69` for the
     * mirror of this same change.
     */
    public function up(): void
    {
        Schema::create('warehouse_bins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('location_id')->index();
            $table->string('zone')->nullable();
            $table->string('rack')->nullable();
            $table->string('bay')->nullable();
            $table->string('position')->nullable();
            $table->timestamps();
        });

        Schema::table('sheet_lots', function (Blueprint $table) {
            $table->uuid('warehouse_bin_id')->nullable()->index();
        });

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->uuid('sheet_lot_id')->nullable()->index();
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->uuid('sheet_lot_id')->nullable()->index();
            $table->decimal('sheet_cut_width', 15, 4)->nullable();
            $table->decimal('sheet_cut_height', 15, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropColumn(['sheet_lot_id', 'sheet_cut_width', 'sheet_cut_height']);
        });

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropColumn('sheet_lot_id');
        });

        Schema::table('sheet_lots', function (Blueprint $table) {
            $table->dropColumn('warehouse_bin_id');
        });

        Schema::dropIfExists('warehouse_bins');
    }
};
