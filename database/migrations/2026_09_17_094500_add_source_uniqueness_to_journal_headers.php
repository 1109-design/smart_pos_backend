<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency backstop for journal posting. JournalService::createDraft()
     * already has an app-level check-then-insert per posting service (e.g.
     * SalePostingService::existingJournal()) but that has a race window: two
     * near-simultaneous triggers for the same source (a sync retry racing
     * the original request, or two queue workers) can both pass the
     * "does a journal already exist for this source" check before either
     * commits its insert, producing two journal_headers rows for one
     * business event.
     *
     * This is deliberately NOT a blanket unique constraint on
     * (business_id, source_type, source_id) — several posting services
     * legitimately create more than one journal against the same source
     * over time: AssetPostingService posts one depreciation journal per
     * month for the same asset (same source_id, many journals), GrvPosting
     * posts one journal per partial receipt against the same GRV, and
     * ExpensePostingService reverses-then-reposts on edit, leaving the old
     * (now 'reversed') row and a new one with the identical source_type/
     * source_id. A table-wide constraint on those three columns breaks all
     * three (confirmed by running the full tests/Feature/Accounting suite
     * against it before landing this version).
     *
     * Instead, `idempotency_key` is a nullable, opt-in column: only a
     * posting path that is genuinely one-shot-forever for its source (see
     * JournalService::createDraft()'s $idempotencyKey param) sets it, and
     * only those rows get the uniqueness guarantee. Every other posting
     * path leaves it null, and multiple NULLs never collide on any driver
     * this app runs (sqlite/mysql/pgsql all treat NULL as distinct from
     * NULL in a unique index).
     */
    public function up(): void
    {
        Schema::table('journal_headers', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('source_id');
            $table->unique('idempotency_key', 'journal_headers_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('journal_headers', function (Blueprint $table) {
            $table->dropUnique('journal_headers_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
