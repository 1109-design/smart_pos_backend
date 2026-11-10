<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_debit_notes', function (Blueprint $table) {
            $table->string('fiscal_status')->nullable();
            $table->string('fiscal_receipt_number')->nullable();
            $table->text('fiscal_qr_code')->nullable();
            $table->string('fiscal_error')->nullable();
        });

        Schema::table('zimra_sales', function (Blueprint $table) {
            $table->uuid('customer_debit_note_id')->nullable()->unique();
        });

        // The taxpayer and branch details ZIMRA requires on every printed receipt
        // come from GetConfig, as does the verification URL.
        Schema::table('zimra_devices', function (Blueprint $table) {
            $table->json('fdms_config')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('zimra_devices', function (Blueprint $table) {
            $table->dropColumn('fdms_config');
        });

        Schema::table('zimra_sales', function (Blueprint $table) {
            $table->dropUnique(['customer_debit_note_id']);
            $table->dropColumn('customer_debit_note_id');
        });

        Schema::table('customer_debit_notes', function (Blueprint $table) {
            $table->dropColumn(['fiscal_status', 'fiscal_receipt_number', 'fiscal_qr_code', 'fiscal_error']);
        });
    }
};
