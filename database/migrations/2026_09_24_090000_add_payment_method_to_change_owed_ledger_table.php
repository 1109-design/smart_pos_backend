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
        Schema::table('change_owed_ledger', function (Blueprint $table) {
            // How a claim's payout physically happened (cash, mobile money,
            // bank transfer…) — always null on an issue row. Mirrors the
            // Flutter PaymentMethod.id values so both sides agree.
            $table->string('payment_method')->nullable()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('change_owed_ledger', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
