<?php

namespace App\Services\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Accounting\JournalLine;
use App\Models\SyncRecord;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The double-entry engine: draft a journal, add balanced lines to it, then
 * post it — which copies the lines into the immutable general_ledger table
 * that every report reads. A posted journal is never edited; correcting one
 * means reverse() (a new journal with every debit/credit swapped). See
 * Phase 11's General Ledger Blueprint for the full design — this class is
 * 11a's foundation, deliberately with no auto-posting wired in yet.
 */
class JournalService
{
    /**
     * @param  string|null  $idempotencyKey  Set this ONLY for a posting path
     *                                       that creates at most one journal
     *                                       ever for a given source (e.g.
     *                                       "sale:{$transactionId}") — it's
     *                                       enforced uniquely at the DB
     *                                       level (journal_headers_
     *                                       idempotency_key_unique), closing
     *                                       the race window between a
     *                                       caller's own existingJournal()-
     *                                       style pre-check and this insert.
     *                                       Leave it null for anything that
     *                                       legitimately posts more than one
     *                                       journal against the same source
     *                                       over time (recurring
     *                                       depreciation, multi-part GRV
     *                                       receipts, reverse-then-repost
     *                                       edits) — a duplicate null never
     *                                       collides with another.
     */
    public function createDraft(
        string $businessId,
        string $transDate,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $description = null,
        ?string $idempotencyKey = null,
    ): JournalHeader {
        try {
            return DB::transaction(function () use ($businessId, $transDate, $sourceType, $sourceId, $description, $idempotencyKey) {
                return JournalHeader::create([
                    'business_id' => $businessId,
                    'journal_number' => $this->nextJournalNumber($businessId),
                    'trans_date' => $transDate,
                    'description' => $description,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'idempotency_key' => $idempotencyKey,
                    'status' => 'draft',
                ]);
            });
        } catch (QueryException $e) {
            // Backstop for the check-then-insert race a caller that opted
            // into $idempotencyKey does (existingJournal()-style lookup,
            // then createDraft()): two near-simultaneous callers for the
            // same source can both pass the lookup before either commits.
            // The DB now rejects the second insert via
            // journal_headers_idempotency_key_unique — treat that specific
            // collision as "someone else already won this post" and hand
            // back their row, so the caller's addLine()/post() sequence
            // degrades to a no-op via JournalHeader::canEdit() instead of
            // crashing. Anything else (e.g. a genuine journal_number
            // collision, which shouldn't happen given nextJournalNumber()'s
            // locking) still throws.
            if ($idempotencyKey !== null && $this->isUniqueConstraintViolation($e)) {
                $existing = JournalHeader::where('idempotency_key', $idempotencyKey)->first();

                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 (integrity constraint violation) is the portable
        // signal across sqlite (tests), mysql and pgsql — Laravel's
        // QueryException::getCode() surfaces the driver's SQLSTATE here.
        return $e->getCode() === '23000';
    }

    /**
     * @param  array{gl_account_id: string, debit?: float, credit?: float, currency_code?: string, exchange_rate?: float, foreign_debit?: float, foreign_credit?: float, party_type?: string|null, party_id?: string|null, description?: string|null}  $data
     */
    public function addLine(JournalHeader $header, array $data): JournalLine
    {
        throw_unless($header->canEdit(), new RuntimeException(
            "Cannot add a line to journal {$header->journal_number} — it is {$header->status}, not draft."
        ));

        return $header->lines()->create([
            'gl_account_id' => $data['gl_account_id'],
            'debit' => $data['debit'] ?? 0,
            'credit' => $data['credit'] ?? 0,
            'currency_code' => $data['currency_code'] ?? 'USD',
            'exchange_rate' => $data['exchange_rate'] ?? 1,
            'foreign_debit' => $data['foreign_debit'] ?? 0,
            'foreign_credit' => $data['foreign_credit'] ?? 0,
            'party_type' => $data['party_type'] ?? null,
            'party_id' => $data['party_id'] ?? null,
            'description' => $data['description'] ?? null,
        ]);
    }

    public function removeLine(JournalLine $line): void
    {
        throw_unless($line->header?->canEdit(), new RuntimeException(
            'Cannot remove a line from a journal that is not draft.'
        ));

        $line->delete();
    }

    public function isBalanced(JournalHeader $header): bool
    {
        $totals = $header->lines()->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();

        return abs((float) $totals->d - (float) $totals->c) < 0.005;
    }

    /**
     * Validates the journal balances and its period is open, copies every
     * line into general_ledger, then locks the header. All inside one
     * transaction — a failed positive-balance constraint (see
     * assertPositiveConstraints) rolls the whole post back.
     */
    public function post(JournalHeader $header, ?string $userId = null): JournalHeader
    {
        return DB::transaction(function () use ($header, $userId) {
            $header = JournalHeader::where('id', $header->id)->lockForUpdate()->firstOrFail();

            throw_unless($header->status === 'draft', new RuntimeException(
                "Journal {$header->journal_number} is already {$header->status}."
            ));

            $lines = $header->lines()->get();

            throw_if($lines->isEmpty(), new RuntimeException(
                "Cannot post journal {$header->journal_number} — it has no lines."
            ));

            throw_unless($this->isBalanced($header), new RuntimeException(
                "Journal {$header->journal_number} does not balance — debits and credits differ."
            ));

            if (AccountingPeriod::isClosedFor($header->business_id, $header->trans_date->toDateString())) {
                throw new RuntimeException(
                    "The accounting period covering {$header->trans_date->toDateString()} is closed. ".
                    'Post a reversing entry dated in the current open period instead.'
                );
            }

            foreach ($lines as $line) {
                GeneralLedgerEntry::create([
                    'business_id' => $header->business_id,
                    'trans_date' => $header->trans_date,
                    'journal_header_id' => $header->id,
                    'gl_account_id' => $line->gl_account_id,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'currency_code' => $line->currency_code,
                    'exchange_rate' => $line->exchange_rate,
                    'foreign_debit' => $line->foreign_debit,
                    'foreign_credit' => $line->foreign_credit,
                    'party_type' => $line->party_type,
                    'party_id' => $line->party_id,
                    'description' => $line->description,
                    'status' => 'active',
                ]);
            }

            $this->assertPositiveConstraints($header, $lines);

            $header->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by_user_id' => $userId,
            ]);

            $header = $header->fresh();
            $this->publishJournal($header);

            return $header;
        });
    }

    /**
     * Creates a new journal with every line's debit/credit (and
     * foreign_debit/foreign_credit) swapped, posts it immediately, and
     * marks the original as reversed. The original's general_ledger rows
     * are tagged 'reversed' — informational only, they still count in every
     * report sum (see GeneralLedgerEntry's own doc comment).
     */
    public function reverse(JournalHeader $original, ?string $userId = null, ?string $reason = null): JournalHeader
    {
        return DB::transaction(function () use ($original, $userId, $reason) {
            $original = JournalHeader::where('id', $original->id)->lockForUpdate()->firstOrFail();

            throw_unless($original->status === 'posted', new RuntimeException(
                "Only a posted journal can be reversed — {$original->journal_number} is {$original->status}."
            ));

            $description = 'Reversal of '.$original->journal_number.($reason ? ": {$reason}" : '');

            $reversal = $this->createDraft(
                $original->business_id,
                now()->toDateString(),
                'reversal',
                $original->id,
                $description,
            );
            $reversal->update(['reversal_of_journal_id' => $original->id]);

            foreach ($original->lines()->get() as $line) {
                $this->addLine($reversal, [
                    'gl_account_id' => $line->gl_account_id,
                    'debit' => (float) $line->credit,
                    'credit' => (float) $line->debit,
                    'currency_code' => $line->currency_code,
                    'exchange_rate' => (float) $line->exchange_rate,
                    'foreign_debit' => (float) $line->foreign_credit,
                    'foreign_credit' => (float) $line->foreign_debit,
                    'party_type' => $line->party_type,
                    'party_id' => $line->party_id,
                    'description' => $line->description,
                ]);
            }

            $this->post($reversal, $userId);

            GeneralLedgerEntry::where('journal_header_id', $original->id)->update(['status' => 'reversed']);

            $original->update([
                'status' => 'reversed',
                'reversed_by_journal_id' => $reversal->id,
                'reversed_at' => now(),
                'reversed_by_user_id' => $userId,
            ]);
            // The reversal itself was already published by post() above;
            // the original's status/reversed_* fields (and its
            // general_ledger rows' status flip to 'reversed') just
            // changed and need republishing too.
            $this->publishJournal($original->fresh());

            return $reversal->fresh();
        });
    }

    /**
     * Publishes a header (and, once posted/reversed, its lines and
     * general_ledger rows) as SyncRecords so every device — including the
     * one that didn't originate this journal — eventually sees it. This is
     * the single choke point every posting service (SalePostingService,
     * GrvPostingService, StockTakePostingService, manual BackOffice
     * entries, reversals) passes through, so instrumenting it here once
     * covers all of them.
     */
    private function publishJournal(JournalHeader $header): void
    {
        $this->publish('journal_headers', $header->business_id, $header->id, [
            'id' => $header->id,
            'business_id' => $header->business_id,
            'journal_number' => $header->journal_number,
            'trans_date' => $header->trans_date->toDateString(),
            'description' => $header->description,
            'source_type' => $header->source_type,
            'source_id' => $header->source_id,
            'status' => $header->status,
            'posted_at' => $header->posted_at?->toIso8601String(),
            'posted_by_user_id' => $header->posted_by_user_id,
            'reversed_by_journal_id' => $header->reversed_by_journal_id,
            'reversed_at' => $header->reversed_at?->toIso8601String(),
            'reversed_by_user_id' => $header->reversed_by_user_id,
            'reversal_of_journal_id' => $header->reversal_of_journal_id,
        ]);

        foreach ($header->lines()->get() as $line) {
            $this->publish('journal_lines', $header->business_id, $line->id, [
                'id' => $line->id,
                'journal_header_id' => $line->journal_header_id,
                'gl_account_id' => $line->gl_account_id,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'currency_code' => $line->currency_code,
                'exchange_rate' => (float) $line->exchange_rate,
                'foreign_debit' => (float) $line->foreign_debit,
                'foreign_credit' => (float) $line->foreign_credit,
                'party_type' => $line->party_type,
                'party_id' => $line->party_id,
                'description' => $line->description,
            ]);
        }

        foreach (GeneralLedgerEntry::where('journal_header_id', $header->id)->get() as $entry) {
            $this->publish('general_ledger', $header->business_id, $entry->id, $this->buildLedgerEntryPayload($entry));
        }
    }

    /**
     * The full, current field set for one general_ledger row — used both
     * when a journal is first posted/reversed above, and by
     * BankReconciliationService::toggleLine()/cancel() to republish an
     * already-posted row after only its reconciliation tag changed. Every
     * field must be listed here: general_ledger's sync case is a full-row
     * upsert (no partial patching), so omitting one silently resets it on
     * every other device — see this table's own sync-case doc comment in
     * SyncProcessor.
     *
     * @return array<string, mixed>
     */
    public function buildLedgerEntryPayload(GeneralLedgerEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'business_id' => $entry->business_id,
            'trans_date' => $entry->trans_date->toDateString(),
            'journal_header_id' => $entry->journal_header_id,
            'gl_account_id' => $entry->gl_account_id,
            'debit' => (float) $entry->debit,
            'credit' => (float) $entry->credit,
            'currency_code' => $entry->currency_code,
            'exchange_rate' => (float) $entry->exchange_rate,
            'foreign_debit' => (float) $entry->foreign_debit,
            'foreign_credit' => (float) $entry->foreign_credit,
            'party_type' => $entry->party_type,
            'party_id' => $entry->party_id,
            'description' => $entry->description,
            'status' => $entry->status,
            'reconciled_at' => $entry->reconciled_at?->toIso8601String(),
            'bank_reconciliation_id' => $entry->bank_reconciliation_id,
        ];
    }

    /**
     * Republishes a single already-posted general_ledger row as-is — for
     * when only its own metadata (reconciliation tag) changed, not the
     * journal it belongs to.
     */
    public function republishLedgerEntry(GeneralLedgerEntry $entry): void
    {
        $this->publish('general_ledger', $entry->business_id, $entry->id, $this->buildLedgerEntryPayload($entry));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publish(string $table, string $businessId, string $uuid, array $payload): void
    {
        SyncRecord::create([
            'business_id' => $businessId,
            'table_name' => $table,
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }

    /**
     * Atomic per-business, per-year sequence (JNL-2026-00001, ...) — a plain
     * count()+1 races under concurrent posts, so this locks every existing
     * number for the year before computing the next one. Must be called
     * from within an active transaction (createDraft wraps it) for the lock
     * to actually serialize concurrent callers.
     */
    private function nextJournalNumber(string $businessId): string
    {
        $prefix = 'JNL-'.now()->year.'-';

        $numbers = JournalHeader::where('business_id', $businessId)
            ->where('journal_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('journal_number');

        $max = $numbers
            ->map(fn (string $n) => (int) substr($n, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * An account flagged must_be_positive (e.g. a cash/bank account) can
     * never be pushed negative by a post — checked after the ledger rows
     * are written so the balance already reflects this journal, inside the
     * same transaction so a violation rolls the whole post back.
     */
    private function assertPositiveConstraints(JournalHeader $header, $lines): void
    {
        $accountIds = $lines->pluck('gl_account_id')->unique();

        $accounts = GlAccount::whereIn('id', $accountIds)->where('must_be_positive', true)->get();

        foreach ($accounts as $account) {
            if ($account->balance() < -0.005) {
                throw new RuntimeException(
                    "Posting journal {$header->journal_number} would take account ".
                    "{$account->code} ({$account->name}) negative, which isn't allowed for this account."
                );
            }
        }
    }
}
