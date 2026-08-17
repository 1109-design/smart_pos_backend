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
        Schema::table('transactions', function (Blueprint $table) {
            // Non-cash payment surcharge (e.g. EcoCash/card handling fee),
            // configured per business in Settings → Payment Methods rather
            // than hardcoded. Included in `total` for till reconciliation,
            // but — same as deposit_total — deliberately excluded from
            // `tax_total`/`transaction_items` so ZIMRA fiscalisation and
            // revenue reports never see it.
            $table->decimal('surcharge_total', 15, 4)->default(0)->after('deposit_total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('surcharge_total');
        });
    }
};
