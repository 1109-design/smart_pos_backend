<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock-take v2 foundation (mirrors the till's schemaVersion 103).
 *
 * stock_takes: what a take covers — scope_type all|category|product|bin,
 * scope_bin_ids (JSON array of warehouse_bins ids, for a bin-scoped take)
 * and a human scope_label.
 *
 * stock_take_items: damaged_qty (non-sellable units counted separately
 * from counted_qty, written off at approval), damage_breakdown (JSON
 * reason => qty, e.g. {"Damaged":2,"Expired":1}), counted_at /
 * counted_by_user_id (who counted the line and when) and a
 * warehouse_bin_id snapshot taken when the take was created.
 *
 * JSON columns are plain text: the device pushes them already encoded and
 * pulls them back verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_takes', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_takes', 'scope_type')) {
                $table->string('scope_type')->default('all')->after('review_comment');
            }
            if (! Schema::hasColumn('stock_takes', 'scope_bin_ids')) {
                $table->text('scope_bin_ids')->nullable()->after('scope_type');
            }
            if (! Schema::hasColumn('stock_takes', 'scope_label')) {
                $table->string('scope_label')->nullable()->after('scope_bin_ids');
            }
        });

        Schema::table('stock_take_items', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_take_items', 'damaged_qty')) {
                $table->decimal('damaged_qty', 15, 4)->nullable()->after('recount_completed_at');
            }
            if (! Schema::hasColumn('stock_take_items', 'damage_breakdown')) {
                $table->text('damage_breakdown')->nullable()->after('damaged_qty');
            }
            if (! Schema::hasColumn('stock_take_items', 'counted_at')) {
                $table->timestamp('counted_at')->nullable()->after('damage_breakdown');
            }
            if (! Schema::hasColumn('stock_take_items', 'counted_by_user_id')) {
                $table->uuid('counted_by_user_id')->nullable()->after('counted_at');
            }
            if (! Schema::hasColumn('stock_take_items', 'warehouse_bin_id')) {
                $table->uuid('warehouse_bin_id')->nullable()->after('counted_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_take_items', function (Blueprint $table) {
            foreach (['warehouse_bin_id', 'counted_by_user_id', 'counted_at', 'damage_breakdown', 'damaged_qty'] as $column) {
                if (Schema::hasColumn('stock_take_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('stock_takes', function (Blueprint $table) {
            foreach (['scope_label', 'scope_bin_ids', 'scope_type'] as $column) {
                if (Schema::hasColumn('stock_takes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
