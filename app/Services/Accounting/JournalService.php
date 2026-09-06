<?php

namespace App\Services\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Accounting\JournalLine;
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
    public function createDraft(
        string $businessId,
        string $transDate,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $description = null,
    ): JournalHeader {
        return DB::transaction(function () use ($businessId, $transDate, $sourceType, $sourceId, $description) {
            return JournalHeader::create([
                'business_id' => $businessId,
                'journal_number' => $this->nextJournalNumber($businessId),
                'trans_date' => $transDate,
                'description' => $description,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'status' => 'draft',
            ]);
        });
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

            return $header->fresh();
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

            return $reversal->fresh();
        });
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
