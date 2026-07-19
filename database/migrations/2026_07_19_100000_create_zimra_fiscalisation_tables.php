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
        // Per-business master switch: fiscalisation is opt-in per business.
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('fiscalisation_enabled')->default(false)->after('subscription_tier');
            $table->string('tin', 20)->nullable()->after('tax_number');
        });

        // Environment-level ZIMRA API configuration (DB-driven, per environment).
        Schema::create('zimra_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('environment')->default('production');
            $table->string('public_api_url');
            $table->string('device_api_url');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->unsignedSmallInteger('max_retries')->default(3);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique('environment');
        });

        // A registered ZIMRA fiscal device, owned by a business.
        Schema::create('zimra_devices', function (Blueprint $table) {
            $table->id();
            $table->string('business_id')->index();
            $table->string('tin', 20);
            $table->string('device_id')->unique();
            $table->string('device_serial_no')->nullable();
            $table->string('activation_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('device_model_name')->default('Server');
            $table->string('device_model_version')->default('v1');
            $table->string('status')->default('registered');
            $table->text('error_message')->nullable();
            $table->json('tax_codes')->nullable();
            $table->json('applicable_taxes')->nullable();
            $table->longText('certificate_data')->nullable();
            $table->longText('private_key_data')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->timestamp('fiscal_day_opened_at')->nullable();
            $table->unsignedSmallInteger('fiscal_day_max_hours')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
        });

        // One row per fiscalisation attempt/outcome, keyed to the POS transaction.
        Schema::create('zimra_sales', function (Blueprint $table) {
            $table->id();
            $table->uuid('transaction_id')->unique();
            $table->string('business_id')->index();
            $table->string('tin', 20)->nullable();
            $table->string('device_id')->nullable()->index();
            $table->string('status')->default('pending'); // pending | retry | fiscalised | failed
            $table->string('zimra_receipt_id')->nullable();
            $table->text('zimra_qr_code')->nullable();
            $table->text('zimra_reference')->nullable();
            $table->string('device_signature_hash')->nullable();
            $table->unsignedBigInteger('receipt_global_no')->nullable();
            $table->string('fiscal_invoice_no')->nullable();
            $table->json('zimra_response')->nullable();
            $table->json('submitted_receipt')->nullable();
            $table->json('retry_history')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('zimra_server_date')->nullable();
            $table->timestamp('fiscalised_at')->nullable();
            $table->timestamps();
        });

        // Fiscal outcome mirrored onto the transaction for easy display/sync-pull.
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('fiscal_status')->nullable()->after('notes'); // pending | fiscalised | failed | not_configured
            $table->string('fiscal_receipt_number')->nullable()->after('fiscal_status');
            $table->text('fiscal_qr_code')->nullable()->after('fiscal_receipt_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['fiscal_status', 'fiscal_receipt_number', 'fiscal_qr_code']);
        });
        Schema::dropIfExists('zimra_sales');
        Schema::dropIfExists('zimra_devices');
        Schema::dropIfExists('zimra_configurations');
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['fiscalisation_enabled', 'tin']);
        });
    }
};
