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
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('location_id')->nullable()->index();
            $table->uuid('customer_id')->index();
            // The accepted quotation this invoice was raised against, if
            // any — nullable so a consolidated invoice (multiple quotes) or
            // a standalone invoice can still be created directly.
            $table->uuid('quotation_id')->nullable()->index();
            $table->string('invoice_number')->index(); // client-generated, see quotations.quote_number
            $table->string('type')->default('standard'); // standard | pro_forma
            // draft | sent | partial | paid | overdue | cancelled
            $table->string('status')->default('draft');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->unsignedInteger('payment_terms_days')->default(0);
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('discount_total', 15, 4)->default(0);
            $table->decimal('tax_total', 15, 4)->default(0);
            $table->decimal('deposit_required', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->decimal('amount_paid', 15, 4)->default(0);
            $table->uuid('recurring_schedule_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->uuid('created_by_user_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
