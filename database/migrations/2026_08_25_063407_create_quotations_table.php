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
        Schema::create('quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('location_id')->nullable()->index();
            $table->uuid('customer_id')->index();
            // Client-generated (offline-safe) — not unique, same accepted
            // eventual-collision tradeoff as purchase_orders.po_number.
            $table->string('quote_number')->index();
            // draft | sent | accepted | rejected | expired | converted
            $table->string('status')->default('draft');
            $table->date('valid_until')->nullable();
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('discount_total', 15, 4)->default(0);
            $table->decimal('tax_total', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->text('notes')->nullable();
            // Set when this quote is a revision of an earlier one — see
            // "Duplicate an existing quotation" / revision history in the spec.
            $table->uuid('parent_quotation_id')->nullable();
            $table->uuid('created_by_user_id');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
