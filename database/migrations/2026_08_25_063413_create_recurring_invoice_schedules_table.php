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
        Schema::create('recurring_invoice_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('customer_id')->index();
            // Snapshot of line items + terms used to generate each new
            // invoice: [{product_id, product_name, quantity, unit_price,
            // discount_pct, tax_rate_id}], plus payment_terms_days/notes.
            $table->json('template_json');
            $table->string('frequency'); // weekly | monthly | quarterly
            $table->date('next_run_date');
            $table->boolean('is_active')->default(true);
            $table->uuid('last_generated_invoice_id')->nullable();
            $table->uuid('created_by_user_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_invoice_schedules');
    }
};
