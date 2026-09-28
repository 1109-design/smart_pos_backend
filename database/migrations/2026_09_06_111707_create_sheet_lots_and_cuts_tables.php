<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GLS·01 — a sheet product isn't tracked as a fungible count; each
     * physical sheet purchased is its own row with its own remaining area.
     * Deliberately area-only, not width/height, once a lot has been cut:
     * real 2D nesting/shape-fit is a physical cutting-floor decision, not
     * something the record needs to model — "priced by area" and "how much
     * is left" only need a number, not a shape. Cutting decrements a lot's
     * `area` in place (see SheetCut, the audit ledger) rather than spawning
     * a new row for the remainder — the remainder simply *is* the same lot
     * with less area, which is what "the remainder becomes sellable stock"
     * means in practice: nothing new to create, nothing lost either.
     */
    public function up(): void
    {
        Schema::create('sheet_lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('product_id')->index();
            $table->uuid('location_id')->nullable();
            // The sheet's dimensions as purchased — kept for reference even
            // after cuts reduce `area`; null is never expected but not
            // enforced, since a device could theoretically omit it.
            $table->decimal('original_width', 15, 4)->nullable();
            $table->decimal('original_height', 15, 4)->nullable();
            $table->decimal('area', 15, 4); // remaining, sellable area
            // available | exhausted (area reached ~0)
            $table->string('status')->default('available');
            $table->uuid('received_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'product_id', 'status']);
        });

        Schema::create('sheet_cuts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sheet_lot_id')->index();
            $table->decimal('width', 15, 4);
            $table->decimal('height', 15, 4);
            $table->decimal('area', 15, 4); // width * height, snapshotted
            $table->uuid('transaction_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamp('cut_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sheet_cuts');
        Schema::dropIfExists('sheet_lots');
    }
};
