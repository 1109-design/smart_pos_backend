<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 11a — the double-entry engine itself: journal_headers (one row
     * per accounting event) -> journal_lines (its debit/credit lines, only
     * ever mutable while the header is draft) -> general_ledger (an
     * immutable copy of a header's lines, made at the instant of posting;
     * every report reads only this table, never journal_lines). Correcting
     * a posted mistake means reversing it — a new journal with every
     * debit/credit swapped — never editing history. Modeled on Blackrock
     * ERP's finance_journal_headers/finance_general_journals/
     * finance_general_ledgers split.
     */
    public function up(): void
    {
        Schema::create('journal_headers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            // Atomic per-business sequence — see JournalService::nextJournalNumber(),
            // deliberately not a plain count()+1 (that races under concurrent posts).
            $table->string('journal_number');
            $table->date('trans_date');
            $table->string('description')->nullable();
            // What triggered this journal — 'sale', 'grv', 'expense', 'asset',
            // 'manual', 'reversal', etc. — plus the originating record's id.
            // Nullable/free-text rather than a rigid polymorphic pair, since
            // the set of source types grows as later sub-phases wire in.
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->enum('status', ['draft', 'posted', 'reversed'])->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->uuid('posted_by_user_id')->nullable();
            // Set on the ORIGINAL header once it has been reversed.
            $table->uuid('reversed_by_journal_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->uuid('reversed_by_user_id')->nullable();
            // Set on the REVERSAL header, pointing back at what it reverses.
            $table->uuid('reversal_of_journal_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['business_id', 'journal_number']);
            $table->index(['business_id', 'trans_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('journal_header_id')->index();
            $table->uuid('gl_account_id')->index();
            // Base-currency amounts — what every report sums.
            $table->decimal('debit', 15, 4)->default(0);
            $table->decimal('credit', 15, 4)->default(0);
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('exchange_rate', 20, 8)->default(1);
            // Transaction-currency amounts, when currency_code isn't the
            // business's base currency — mirrors Payment.base_equivalent's
            // existing convention elsewhere in SMARTPOS.
            $table->decimal('foreign_debit', 15, 4)->default(0);
            $table->decimal('foreign_credit', 15, 4)->default(0);
            // Set only on a line posted to a control account (Phase 11c) —
            // e.g. party_type='customer', party_id=Customer.id — so the
            // debtors/creditors sub-ledger can be derived straight from
            // these lines instead of a separately-maintained balance.
            $table->string('party_type')->nullable();
            $table->uuid('party_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('general_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Denormalized (also on journal_headers) so every report query
            // filters this one table directly without joining back.
            $table->string('business_id')->index();
            $table->date('trans_date');
            $table->uuid('journal_header_id')->index();
            $table->uuid('gl_account_id')->index();
            $table->decimal('debit', 15, 4)->default(0);
            $table->decimal('credit', 15, 4)->default(0);
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->decimal('foreign_debit', 15, 4)->default(0);
            $table->decimal('foreign_credit', 15, 4)->default(0);
            $table->string('party_type')->nullable();
            $table->uuid('party_id')->nullable();
            $table->string('description')->nullable();
            // Informational only — a 'reversed' row still counts in every
            // sum (its reversal posted the exact opposite amounts; excluding
            // it would unbalance the books). It only marks "this fact has
            // since been corrected" for display/audit purposes.
            $table->enum('status', ['active', 'reversed'])->default('active');
            $table->timestamp('created_at')->nullable();

            $table->index(['business_id', 'gl_account_id']);
            $table->index(['business_id', 'party_type', 'party_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('general_ledger');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_headers');
    }
};
