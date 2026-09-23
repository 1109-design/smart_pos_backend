<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The currency the cashier picked in the till's Transfer to Vault /
     * Add to Drawer dialog. Nullable for backwards-compat with existing
     * rows (null = USD / base, matching the Flutter till's own fallback in
     * payment_ledger_service.dart). Mirrors the Flutter till app's
     * till_cash_movements.currency_code column — without this, the currency
     * a movement was recorded in was silently dropped by SyncProcessor on
     * its way to the backend and to every other synced device.
     */
    public function up(): void
    {
        Schema::table('till_cash_movements', function (Blueprint $table) {
            $table->string('currency_code')->nullable()->after('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('till_cash_movements', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });
    }
};
