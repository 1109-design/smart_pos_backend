<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinct from `reserved_quantity` (order-holds only, unchanged) —
     * before this column, TransferService::dispatch() borrowed
     * reserved_quantity for a completely different purpose (stock committed
     * to an in-transit transfer), so the two concepts collided in one field
     * and the destination location had zero visibility of incoming stock
     * until the transfer was actually received.
     */
    public function up(): void
    {
        Schema::table('product_stock', function (Blueprint $table) {
            $table->decimal('in_transit_quantity', 15, 4)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_stock', function (Blueprint $table) {
            $table->dropColumn('in_transit_quantity');
        });
    }
};
