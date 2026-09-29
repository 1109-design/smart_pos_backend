<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods inspection at receiving: one row per line per receiving session,
     * recording the condition check the clerk made before confirming the
     * receipt (passed, or why units were turned away). Written by the till's
     * receiving screen and never updated or deleted — unlike
     * po_receipt_variances, which is a running per-PO total that disappears
     * once quantities reconcile.
     */
    public function up(): void
    {
        Schema::create('receipt_inspections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('purchase_order_id')->index();
            $table->uuid('purchase_order_item_id')->nullable();
            $table->uuid('product_id')->index();
            $table->string('product_name');
            $table->decimal('delivered_qty', 15, 4);
            $table->decimal('accepted_qty', 15, 4);
            $table->decimal('rejected_qty', 15, 4)->default(0);
            // passed | damaged | wrong_item | expired | poor_quality | other
            $table->string('result');
            $table->text('notes')->nullable();
            $table->string('inspected_by_user_id');
            $table->string('inspected_by_name');
            $table->timestamp('inspected_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_inspections');
    }
};
