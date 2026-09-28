<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Null (the default) means Phase 11 accounting posting is entirely
     * inactive for this business — SalePostingService and the
     * accounting:post-pending-sales sweep both no-op until this is set.
     * Deliberately not auto-populated at provisioning: setting it is what
     * commits to "opening balances, not a historical rebuild" (see the
     * Phase 11 General Ledger Blueprint's cutover section) — posting every
     * transaction ever made the moment this shipped would silently violate
     * that decision for any business with existing history. A real
     * BackOffice setting for this belongs to Phase 11f (opening-balance
     * tooling); `accounting:set-go-live-date` is the stopgap until then.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->date('accounting_go_live_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('accounting_go_live_date');
        });
    }
};
