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
        // Business-level default for the checkout "Fiscalise this sale"
        // toggle — distinct from fiscalisation_enabled (the master switch).
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('fiscalise_by_default')->default(true)->after('fiscalisation_enabled');
        });

        // Cashier intent, captured per sale — distinct from fiscal_status
        // (the ZIMRA outcome). false means the cashier unticked the
        // checkout toggle: this sale must never be queued for ZIMRA.
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('fiscalisation_requested')->default(true)->after('fiscal_qr_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('fiscalise_by_default');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('fiscalisation_requested');
        });
    }
};
