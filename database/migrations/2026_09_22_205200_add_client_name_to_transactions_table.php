<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional free-text walk-in name on a sale, independent of customer_id —
     * lets a cashier note who a sale was for without selecting/creating a
     * full Customer record. Mirrors the Flutter till app's clientName column
     * (schema v88); sync/backup only, no server-side logic reads this.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->text('client_name')->nullable()->after('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('client_name');
        });
    }
};
