<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extends the till-populated product request log with the customer
     * contact details and restock-detection fields the Flutter app now
     * writes (see smart_pos's StockRequestService) — the table previously
     * only recorded the item name/note, with no way to tell the asking
     * customer their item arrived.
     */
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->uuid('product_id')->nullable()->after('location_id');
            $table->string('customer_name')->nullable()->after('product_name');
            $table->string('customer_phone')->nullable()->after('customer_name');
            $table->decimal('quantity', 12, 2)->nullable()->after('customer_phone');
            $table->timestamp('stock_available_at')->nullable()->after('status');
            $table->uuid('notified_by_user_id')->nullable()->after('stock_available_at');
            $table->timestamp('notified_at')->nullable()->after('notified_by_user_id');
            $table->string('sms_message_id')->nullable()->after('notified_at');
            $table->timestamp('updated_at')->nullable()->after('created_at');

            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'status']);
            $table->dropColumn([
                'product_id', 'customer_name', 'customer_phone', 'quantity',
                'stock_available_at', 'notified_by_user_id', 'notified_at',
                'sms_message_id', 'updated_at',
            ]);
        });
    }
};
