<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retires the free-text "Payment Details" bank list in favour of the
     * real, GL-linked bank_accounts table — see the matching Flutter-side
     * one-time migration (_migrateBankAccountsJsonToBankAccounts in
     * app_database.dart) that copies every business's entries across
     * before this column stops being read anywhere.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('bank_accounts_json');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('bank_accounts_json')->nullable()->after('tin');
        });
    }
};
