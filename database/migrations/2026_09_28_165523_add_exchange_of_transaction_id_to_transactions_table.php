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
            // Set only on a product exchange: the sale whose items the
            // customer brought back. The exchange itself is an ordinary
            // 'completed' transaction (negative returned lines, positive
            // replacement lines, total = net difference) — this link is
            // what marks it as one for approval gating, fiscalisation and
            // posting.
            $table->string('exchange_of_transaction_id')->nullable()->after('void_reason')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['exchange_of_transaction_id']);
            $table->dropColumn('exchange_of_transaction_id');
        });
    }
};
