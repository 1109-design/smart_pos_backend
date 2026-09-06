<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purchasing & Cash Vault Blueprint, part A — a real receiving document
     * instead of the till just editing purchase_order_items directly. Not
     * something anyone fills in by hand: GrvPostingService creates one
     * automatically whenever a synced stock_movement (type='receive') turns
     * out to reference a real PurchaseOrder — walk-in receiving (no PO, no
     * known supplier) deliberately never creates one. One GRV per
     * (business, purchase_order, calendar day) — items received against the
     * same PO on the same day land on the same voucher; a later day starts
     * a new one.
     */
    public function up(): void
    {
        Schema::create('goods_received_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            // Atomic per-business sequence — see GrvPostingService::nextGrvNumber(),
            // same reasoning as JournalService's journal numbering.
            $table->string('grv_number');
            $table->uuid('purchase_order_id')->index();
            $table->uuid('supplier_id')->nullable()->index();
            $table->date('received_date');
            $table->timestamps();

            $table->unique(['business_id', 'grv_number']);
        });

        Schema::create('grv_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('grv_id')->index();
            // One row per stock_movement processed — the idempotency guard
            // against the same movement being synced/processed twice.
            $table->uuid('stock_movement_id')->unique();
            $table->uuid('product_id')->index();
            $table->string('product_name');
            $table->decimal('quantity_received', 15, 4);
            // Accepted vs rejected split exists in the schema now even
            // though the till doesn't yet have a UI to enter a rejection —
            // quantity_accepted always equals quantity_received until it
            // does. See the Purchasing & Cash Vault Blueprint §3.
            $table->decimal('quantity_accepted', 15, 4);
            $table->decimal('quantity_rejected', 15, 4)->default(0);
            $table->string('rejection_reason')->nullable();
            $table->decimal('unit_cost', 15, 4);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grv_items');
        Schema::dropIfExists('goods_received_vouchers');
    }
};
