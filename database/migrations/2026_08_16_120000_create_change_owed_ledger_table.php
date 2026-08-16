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
        Schema::create('change_owed_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('location_id')->nullable();
            $table->uuid('customer_id')->nullable()->index();
            $table->uuid('transaction_id')->index();
            // Signed: positive = change owed (issue, at sale time), negative
            // = paid out against a claim. Mirrors container_deposit_ledger.
            $table->decimal('amount', 15, 4);
            // Claimed back in the same currency it was owed in — never
            // re-converted using a later (possibly different) exchange rate.
            $table->string('currency_code');
            $table->string('type'); // issue | claim
            $table->text('reason')->nullable();
            $table->uuid('user_id');
            $table->timestamps();

            $table->index(['business_id', 'transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('change_owed_ledger');
    }
};
