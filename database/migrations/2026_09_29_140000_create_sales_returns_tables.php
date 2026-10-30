<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer returns & exchanges, mirrored from the till (Flutter schema v104,
 * `SalesReturns` in tables.dart). The money and stock travel in the usual
 * transactions/payments/stock_movements rows; these tables link a return to
 * the original sale (so no line can come back twice across tills) and hold
 * the owner's return window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('location_id')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->string('return_number');
            $table->uuid('original_transaction_id')->index();
            $table->uuid('return_transaction_id');
            $table->uuid('exchange_transaction_id')->nullable();
            $table->string('outcome'); // refund | exchange
            $table->decimal('returned_value', 15, 4);
            $table->decimal('new_items_value', 15, 4)->default(0);
            $table->decimal('net_amount', 15, 4);
            $table->string('settlement_method')->nullable();
            $table->text('reason');
            $table->uuid('requested_by_user_id');
            $table->uuid('approved_by_user_id')->nullable();
            $table->uuid('approval_request_id')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sales_return_id')->index();
            $table->uuid('original_transaction_item_id')->index();
            $table->uuid('product_id');
            $table->string('product_name');
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_value', 15, 4);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('line_value', 15, 4);
            $table->string('condition')->default('resellable'); // resellable | damaged
            $table->timestamps();
        });

        Schema::create('sales_return_settings', function (Blueprint $table) {
            $table->string('business_id')->primary();
            $table->unsignedInteger('return_window_days')->default(0); // 0 = no limit
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_settings');
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
    }
};
