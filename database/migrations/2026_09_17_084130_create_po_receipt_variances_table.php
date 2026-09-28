<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (purchase_order, product) that didn't reconcile 1:1 on
     * receiving — short, over, a product delivered that was never on the PO
     * (ordered_qty = 0), and/or units rejected at the door. Written by the
     * till's receiving screen alongside the ordinary purchase_order_items
     * update, independent of GrvPostingService/accountingIsLive() — a
     * variance is an operational fact a manager needs to see whether or not
     * this business has switched accounting on. A line that later reconciles
     * (a follow-up receipt closes the gap) has its row deleted rather than
     * updated to "no variance" — see receive_stock_screen.dart.
     */
    public function up(): void
    {
        Schema::create('po_receipt_variances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('purchase_order_id')->index();
            $table->uuid('purchase_order_item_id')->nullable()->index();
            $table->uuid('product_id')->index();
            $table->string('product_name');
            $table->decimal('ordered_qty', 15, 4)->default(0);
            $table->decimal('received_qty', 15, 4)->default(0);
            $table->decimal('rejected_qty', 15, 4)->default(0);
            $table->decimal('variance_qty', 15, 4)->default(0);
            // unordered | short | over | rejected_only
            $table->string('status');
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->unique(['purchase_order_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('po_receipt_variances');
    }
};
