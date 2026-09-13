<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank reconciliation tags an already-posted general_ledger row as
     * "cleared" without touching any of its financial fields — same
     * precedent as this table's own `status` column already being mutated
     * post-creation (active -> reversed). See BankReconciliationService.
     */
    public function up(): void
    {
        Schema::table('general_ledger', function (Blueprint $table) {
            $table->timestamp('reconciled_at')->nullable();
            $table->uuid('bank_reconciliation_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('general_ledger', function (Blueprint $table) {
            $table->dropColumn(['reconciled_at', 'bank_reconciliation_id']);
        });
    }
};
