<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GLS·02 — extends GLS·01's sheet_lots/sheet_cuts with genealogy
     * (parent/root lot, replacing the old in-place-area-shrink model),
     * reservations, cutting-rule columns on products, and a new append-only
     * sheet_loss_records ledger for scrap/breakage/cutting-waste. See the
     * Flutter side's app_database.dart migration `from < 68` for the mirror
     * of this same change.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('sheet_min_usable_width', 15, 4)->nullable();
            $table->decimal('sheet_min_usable_height', 15, 4)->nullable();
            $table->decimal('sheet_kerf_width', 15, 4)->nullable();
            $table->decimal('sheet_cutting_charge', 15, 4)->nullable();
            $table->boolean('sheet_allow_rotate')->default(true);
        });

        Schema::table('sheet_lots', function (Blueprint $table) {
            $table->uuid('parent_lot_id')->nullable()->index();
            $table->uuid('root_lot_id')->nullable()->index();
            $table->string('display_code')->nullable();
            $table->string('bin_location')->nullable();
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->uuid('source_purchase_order_id')->nullable();
            $table->string('reserved_for_type')->nullable();
            $table->uuid('reserved_for_id')->nullable();
            $table->timestamp('reserved_until')->nullable();
            $table->uuid('reserved_by_user_id')->nullable();
        });

        Schema::table('sheet_cuts', function (Blueprint $table) {
            // sold | offcut | scrap
            $table->string('result_kind')->nullable();
            $table->uuid('child_lot_id')->nullable();
            $table->string('reason')->nullable();
        });

        Schema::create('sheet_loss_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('sheet_lot_id')->index();
            $table->uuid('product_id')->index();
            // cutting_waste | scrap | breakage
            $table->string('kind');
            $table->string('reason');
            $table->decimal('width', 15, 4)->nullable();
            $table->decimal('height', 15, 4)->nullable();
            $table->decimal('area', 15, 4);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('financial_impact', 15, 4);
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->uuid('approval_request_id')->nullable();
            $table->uuid('reported_by_user_id')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['business_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_loss_records');

        Schema::table('sheet_cuts', function (Blueprint $table) {
            $table->dropColumn(['result_kind', 'child_lot_id', 'reason']);
        });

        Schema::table('sheet_lots', function (Blueprint $table) {
            $table->dropColumn([
                'parent_lot_id', 'root_lot_id', 'display_code', 'bin_location',
                'unit_cost', 'source_purchase_order_id', 'reserved_for_type',
                'reserved_for_id', 'reserved_until', 'reserved_by_user_id',
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'sheet_min_usable_width', 'sheet_min_usable_height',
                'sheet_kerf_width', 'sheet_cutting_charge', 'sheet_allow_rotate',
            ]);
        });
    }
};
