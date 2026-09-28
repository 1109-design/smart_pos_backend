<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per "tick off the cash book against a bank statement" session
     * for a single BankAccount — see BankReconciliationService. The cleared
     * balance itself is never stored here (derived from general_ledger's
     * new reconciled_at column), same as every other account balance in
     * this system.
     */
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('bank_account_id')->index();
            $table->date('statement_date');
            $table->decimal('statement_balance', 20, 4);
            // in_progress | completed | cancelled — a session is closed by
            // flipping status, never deleted (audit trail for a financial
            // reconciliation must survive a mistaken start).
            $table->string('status')->default('in_progress');
            $table->string('started_by_user_id');
            $table->timestamp('started_at');
            $table->string('completed_by_user_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
