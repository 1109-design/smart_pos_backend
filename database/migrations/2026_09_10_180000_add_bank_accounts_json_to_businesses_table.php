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
        Schema::table('businesses', function (Blueprint $table) {
            // JSON-encoded list of {bank_name, account_name, account_number,
            // branch, currency_code} — see lib/core/business/bank_account.dart
            // on the Flutter side. Printed on the invoice PDF's "Payment
            // Details" section; edited from Settings, not per-transaction.
            $table->json('bank_accounts_json')->nullable()->after('tin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('bank_accounts_json');
        });
    }
};
