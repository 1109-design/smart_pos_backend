<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_container_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('beverage_product_id')->index();
            $table->uuid('container_product_id')->index();
            $table->decimal('quantity_per_unit', 15, 4)->default(1);
        });

        Schema::create('container_deposit_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('location_id')->nullable();
            $table->uuid('customer_id')->nullable()->index();
            $table->uuid('container_product_id')->index();
            $table->uuid('transaction_id')->nullable()->index();
            // Signed: positive = issued (liability up), negative = returned
            // or forfeited (liability down). Mirrors credit_transactions.
            $table->decimal('quantity', 15, 4);
            // Snapshot of the deposit rate at the time of this row — later
            // rate changes never rewrite history.
            $table->decimal('deposit_amount_per_unit', 15, 4);
            $table->string('type'); // issue | return | forfeit | adjustment
            $table->string('refund_method')->nullable(); // cash | credit
            $table->text('reason')->nullable();
            $table->uuid('user_id');
            $table->timestamps();

            $table->index(['business_id', 'container_product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('container_deposit_ledger');
        Schema::dropIfExists('product_container_links');
    }
};
