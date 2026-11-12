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
        // One row per device per fiscal day: what was declared to ZIMRA at close
        // and what ZIMRA replied, kept as the audit record of that day.
        Schema::create('zimra_fiscal_days', function (Blueprint $table) {
            $table->id();
            $table->string('business_id')->index();
            $table->string('device_id');
            $table->unsignedBigInteger('fiscal_day_no');
            $table->string('status')->default('open')->index(); // open | close_submitted | closed | close_failed
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('close_submitted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('receipt_counter')->nullable();
            $table->unsignedBigInteger('last_receipt_global_no')->nullable();
            $table->json('close_counters')->nullable();
            $table->json('close_payload')->nullable();
            $table->json('close_response')->nullable();
            $table->json('close_status_response')->nullable();
            $table->json('close_attempts')->nullable();
            $table->string('closing_error_code')->nullable();
            $table->text('server_signature')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'fiscal_day_no']);
        });

        Schema::table('zimra_sales', function (Blueprint $table) {
            // With device_id, identifies the fiscal day this receipt belongs to.
            $table->unsignedBigInteger('fiscal_day_no')->nullable()->after('device_id');
            $table->index(['device_id', 'fiscal_day_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zimra_sales', function (Blueprint $table) {
            $table->dropIndex(['device_id', 'fiscal_day_no']);
            $table->dropColumn('fiscal_day_no');
        });

        Schema::dropIfExists('zimra_fiscal_days');
    }
};
