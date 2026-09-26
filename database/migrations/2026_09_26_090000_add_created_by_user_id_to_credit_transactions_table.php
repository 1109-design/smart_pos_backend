<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable because rows written before this column existed have no
     * attribution. Lets the till-side customer profile "activity" feed show
     * who set an opening balance — see the matching Drift column's doc
     * comment on CreditTransactions.createdByUserId.
     */
    public function up(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->uuid('created_by_user_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropColumn('created_by_user_id');
        });
    }
};
