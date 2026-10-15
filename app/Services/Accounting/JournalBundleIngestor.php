<?php

namespace App\Services\Accounting;

use App\Exceptions\JournalRejectedException;
use App\Exceptions\MissingParentRecordException;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Accounting\JournalLine;
use App\Models\Device;
use App\Models\SyncRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Accepts a journal a device posted, pushed as one 'journal_postings'
 * record (header + all its lines), and either stores it whole or rejects
 * it whole with a reason code. The server never creates journals itself;
 * this is the gate every device journal passes through.
 *
 * Checks: lines present and one-sided, balanced, accounts exist / belong
 * to the business / are active / allow posting, receivable and payable
 * lines carry a party, the period is open, the posting key and number are
 * not already used by another journal, the device is the one it says it
 * is, sensitive source types come from a permitted role, and a reversal
 * reverses a posted journal of the same business.
 *
 * Idempotent: the same journal pushed again (a retry) is accepted without
 * writing anything. general_ledger rows are built here from the lines and
 * the result is relayed to every other device as ordinary journal_headers
 * / journal_lines / general_ledger records.
 */
class JournalBundleIngestor
{
    /** Source types only an owner or manager may post — see SyncProcessor. */
    private const OWNER_OR_MANAGER_SOURCES = ['salary_payment', 'supplier_payment', 'cash_vault_drop', 'cash_vault_deposit', 'cash_vault_count'];

    private const OWNER_ONLY_SOURCES = ['asset_acquisition', 'asset_disposal'];

    private const TOLERANCE = 0.005;

    /**
     * @param  array{business_id?: string, header?: array<string, mixed>, lines?: list<array<string, mixed>>}  $payload
     */
    public function ingest(string $journalId, array $payload, bool $trusted, ?User $actingUser, ?Device $device): JournalHeader
    {
        $businessId = (string) ($payload['business_id'] ?? '');
        $header = $payload['header'] ?? [];
        $lines = $payload['lines'] ?? [];

        return DB::transaction(function () use ($journalId, $businessId, $header, $lines, $trusted, $actingUser, $device) {
            $existing = JournalHeader::where('id', $journalId)->lockForUpdate()->first();
            if ($existing !== null) {
                return $this->acceptRetry($existing, $businessId, $header, $lines);
            }

            $this->assertSourceAllowed($header['source_type'] ?? null, $trusted, $actingUser);
            $this->assertDevice($header, $device);
            $this->assertLines($businessId, $lines);
            $this->assertPeriodOpen($businessId, (string) ($header['trans_date'] ?? ''), $this->moduleOf($header));
            $this->assertUnique($businessId, $journalId, $header);
            $original = $this->reversedJournal($businessId, $header);

            $journal = JournalHeader::create([
                'id' => $journalId,
                'business_id' => $businessId,
                'journal_number' => $header['journal_number'],
                'trans_date' => $header['trans_date'],
                'description' => $header['description'] ?? null,
                'source_type' => $header['source_type'] ?? null,
                'source_id' => $header['source_id'] ?? null,
                'status' => 'posted',
                'posted_at' => $header['posted_at'] ?? now(),
                'posted_by_user_id' => $header['posted_by_user_id'] ?? null,
                'reversal_of_journal_id' => $header['reversal_of_journal_id'] ?? null,
                'location_id' => $header['location_id'] ?? null,
                'posting_key' => $header['posting_key'],
                'journal_type' => $header['journal_type'] ?? 'system',
                'batch_id' => $header['batch_id'] ?? null,
                'corrects_journal_id' => $header['corrects_journal_id'] ?? null,
                'auto_reverse_date' => $header['auto_reverse_date'] ?? null,
                'posted_device_id' => $header['posted_device_id'] ?? null,
                'cost_centre_id' => $header['cost_centre_id'] ?? null,
                'currency_code' => $header['currency_code'] ?? null,
                'exchange_rate' => $header['exchange_rate'] ?? 1,
            ]);

            foreach ($lines as $line) {
                $values = [
                    'gl_account_id' => $line['gl_account_id'],
                    'debit' => (float) ($line['debit'] ?? 0),
                    'credit' => (float) ($line['credit'] ?? 0),
                    'currency_code' => $line['currency_code'] ?? 'USD',
                    'exchange_rate' => $line['exchange_rate'] ?? 1,
                    'foreign_debit' => $line['foreign_debit'] ?? 0,
                    'foreign_credit' => $line['foreign_credit'] ?? 0,
                    'party_type' => $line['party_type'] ?? null,
                    'party_id' => $line['party_id'] ?? null,
                    'description' => $line['description'] ?? null,
                    'cost_centre_id' => $line['cost_centre_id'] ?? null,
                ];
                JournalLine::create([
                    'id' => $line['id'],
                    'journal_header_id' => $journalId,
                    'line_no' => $line['line_no'] ?? 0,
                    ...$values,
                ]);
                // Same id as its line (a different table) — exactly what the
                // device wrote, so both sides hold identical rows.
                GeneralLedgerEntry::create([
                    'id' => $line['id'],
                    'business_id' => $businessId,
                    'trans_date' => $header['trans_date'],
                    'journal_header_id' => $journalId,
                    'location_id' => $header['location_id'] ?? null,
                    'status' => 'active',
                    ...$values,
                ]);
            }

            $this->publish($journal, $device);

            if ($original !== null) {
                GeneralLedgerEntry::where('journal_header_id', $original->id)->update(['status' => 'reversed']);
                $original->update([
                    'status' => 'reversed',
                    'reversed_by_journal_id' => $journalId,
                    'reversed_at' => $header['posted_at'] ?? now(),
                    'reversed_by_user_id' => $header['posted_by_user_id'] ?? null,
                ]);
                $this->publish($original->fresh(), $device);
            }

            return $journal;
        });
    }

    /**
     * The same journal again: accept it unchanged if it really is the same
     * one, otherwise refuse — a posted journal is never rewritten.
     *
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    private function acceptRetry(JournalHeader $existing, string $businessId, array $header, array $lines): JournalHeader
    {
        $sameTotals = abs((float) $existing->lines()->sum('debit') - $this->sum($lines, 'debit')) < self::TOLERANCE;
        $sameKey = $existing->posting_key === null || $existing->posting_key === ($header['posting_key'] ?? null);

        if ((string) $existing->business_id !== $businessId || ! $sameKey || ! $sameTotals) {
            throw new JournalRejectedException('conflict', "Journal {$existing->id} already exists with different contents and can't be changed — reverse it instead.");
        }

        return $existing;
    }

    private function assertSourceAllowed(?string $sourceType, bool $trusted, ?User $actingUser): void
    {
        if ($trusted || $sourceType === null) {
            return;
        }
        if (in_array($sourceType, self::OWNER_OR_MANAGER_SOURCES, true)
            && ! ($actingUser?->hasRole(['business_owner', 'manager']) ?? false)) {
            throw new JournalRejectedException('forbidden', "Posting a {$sourceType} journal requires owner or manager access.");
        }
        if (in_array($sourceType, self::OWNER_ONLY_SOURCES, true)
            && ! ($actingUser?->hasRole('business_owner') ?? false)) {
            throw new JournalRejectedException('forbidden', "Posting a {$sourceType} journal requires the business owner role.");
        }
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function assertDevice(array $header, ?Device $device): void
    {
        // Devices on one LAN relay each other's records and push them up
        // too, so the pusher may differ from the poster — but the poster
        // must be a device of this business.
        $claimed = $header['posted_device_id'] ?? null;
        if ($device === null || $claimed === null || $claimed === $device->device_identifier) {
            return;
        }
        $sameBusiness = Device::where('tenant_id', $device->tenant_id)->where('device_identifier', $claimed)->exists();
        if (! $sameBusiness) {
            throw new JournalRejectedException('device_mismatch', 'This journal says it was posted by a device outside this business.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertLines(string $businessId, array $lines): void
    {
        if ($lines === []) {
            throw new JournalRejectedException('no_lines', 'The journal has no lines.');
        }

        $accounts = GlAccount::whereIn('id', array_column($lines, 'gl_account_id'))->get()->keyBy('id');

        foreach ($lines as $i => $line) {
            $n = $i + 1;
            $debit = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);
            if (empty($line['id']) || $debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0)) {
                throw new JournalRejectedException('invalid_line', "Line {$n} must have an id and a debit or a credit, not both and not negative.");
            }

            $account = $accounts->get($line['gl_account_id'] ?? '');
            if ($account === null || (string) $account->business_id !== $businessId) {
                throw new JournalRejectedException('unknown_account', "Line {$n} posts to an account this business doesn't have.");
            }
            if ($account->status !== 'active' || ! $account->allow_direct_posting) {
                throw new JournalRejectedException('inactive_account', "Line {$n} posts to {$account->code} {$account->name}, which is inactive or closed to posting.");
            }
            if (in_array($account->control_type, ['receivable', 'payable'], true) && empty($line['party_id'])) {
                throw new JournalRejectedException('missing_party', "Line {$n} posts to {$account->code} {$account->name} without a customer or supplier.");
            }
        }

        $debit = $this->sum($lines, 'debit');
        $credit = $this->sum($lines, 'credit');
        if (abs($debit - $credit) >= self::TOLERANCE) {
            throw new JournalRejectedException('unbalanced', sprintf('The journal does not balance: debits %.2f, credits %.2f.', $debit, $credit));
        }
    }

    /**
     * The module a journal belongs to — a reversal takes its original's.
     *
     * @param  array<string, mixed>  $header
     */
    private function moduleOf(array $header): string
    {
        $sourceType = $header['source_type'] ?? null;
        if (! empty($header['reversal_of_journal_id'])) {
            $sourceType = JournalHeader::where('id', $header['reversal_of_journal_id'])->value('source_type') ?? $sourceType;
        }

        return AccountingPeriod::moduleFor($sourceType);
    }

    private function assertPeriodOpen(string $businessId, string $transDate, string $module): void
    {
        if ($transDate === '') {
            throw new JournalRejectedException('invalid_line', 'The journal has no date.');
        }
        if (AccountingPeriod::isClosedFor($businessId, $transDate, $module)) {
            throw new JournalRejectedException('closed_period', "The {$module} period covering {$transDate} is closed.");
        }
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function assertUnique(string $businessId, string $journalId, array $header): void
    {
        $postingKey = $header['posting_key'] ?? null;
        $number = $header['journal_number'] ?? null;
        if (empty($postingKey) || empty($number)) {
            throw new JournalRejectedException('invalid_line', 'The journal has no posting key or number.');
        }

        $byKey = JournalHeader::where('business_id', $businessId)->where('posting_key', $postingKey)->value('id');
        if ($byKey !== null && $byKey !== $journalId) {
            throw new JournalRejectedException('duplicate', "Journal {$byKey} was already posted for {$postingKey}.");
        }
        if (JournalHeader::where('business_id', $businessId)->where('journal_number', $number)->exists()) {
            throw new JournalRejectedException('duplicate_number', "Journal number {$number} is already used.");
        }
    }

    /**
     * The journal a reversal reverses, which must already be here and
     * posted. Not here yet means it's still on its way up: defer.
     *
     * @param  array<string, mixed>  $header
     */
    private function reversedJournal(string $businessId, array $header): ?JournalHeader
    {
        $originalId = $header['reversal_of_journal_id'] ?? null;
        if ($originalId === null) {
            return null;
        }

        $original = JournalHeader::where('id', $originalId)->lockForUpdate()->first();
        if ($original === null) {
            throw new MissingParentRecordException("journal_postings: the journal being reversed ({$originalId}) has not arrived yet.");
        }
        if ((string) $original->business_id !== $businessId || $original->status !== 'posted') {
            throw new JournalRejectedException('not_reversible', "Journal {$original->journal_number} is {$original->status} and can't be reversed.");
        }

        return $original;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function sum(array $lines, string $side): float
    {
        return round(array_sum(array_map(fn (array $l): float => (float) ($l[$side] ?? 0), $lines)), 4);
    }

    /**
     * Relays the journal to the other devices as ordinary journal records.
     * Tagged with the pushing device so it isn't echoed back to it.
     */
    private function publish(JournalHeader $journal, ?Device $device): void
    {
        $journals = app(JournalService::class);
        $rows = [['journal_headers', $journal->id, $this->headerPayload($journal)]];
        foreach ($journal->lines()->get() as $line) {
            $rows[] = ['journal_lines', $line->id, [
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
                'cost_centre_id' => $line->cost_centre_id,
                'line_no' => $line->line_no,
            ]];
        }
        foreach (GeneralLedgerEntry::where('journal_header_id', $journal->id)->get() as $entry) {
            $rows[] = ['general_ledger', $entry->id, [
                ...$journals->buildLedgerEntryPayload($entry),
                'location_id' => $entry->location_id,
                'cost_centre_id' => $entry->cost_centre_id,
            ]];
        }

        foreach ($rows as [$table, $uuid, $payload]) {
            SyncRecord::create([
                'business_id' => $journal->business_id,
                'table_name' => $table,
                'record_uuid' => $uuid,
                'operation' => 'upsert',
                'payload' => $payload,
                'source_updated_at' => now(),
                'synced_at' => now(),
                'device_id' => $device?->id,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function headerPayload(JournalHeader $h): array
    {
        return [
            'id' => $h->id,
            'business_id' => $h->business_id,
            'journal_number' => $h->journal_number,
            'trans_date' => $h->trans_date->toDateString(),
            'description' => $h->description,
            'source_type' => $h->source_type,
            'source_id' => $h->source_id,
            'status' => $h->status,
            'posted_at' => $h->posted_at?->toIso8601String(),
            'posted_by_user_id' => $h->posted_by_user_id,
            'reversed_by_journal_id' => $h->reversed_by_journal_id,
            'reversed_at' => $h->reversed_at?->toIso8601String(),
            'reversed_by_user_id' => $h->reversed_by_user_id,
            'reversal_of_journal_id' => $h->reversal_of_journal_id,
            'location_id' => $h->location_id,
            'posting_key' => $h->posting_key,
            'journal_type' => $h->journal_type,
            'batch_id' => $h->batch_id,
            'corrects_journal_id' => $h->corrects_journal_id,
            'auto_reverse_date' => $h->auto_reverse_date?->toDateString(),
            'posted_device_id' => $h->posted_device_id,
            'cost_centre_id' => $h->cost_centre_id,
            'currency_code' => $h->currency_code,
            'exchange_rate' => (float) $h->exchange_rate,
        ];
    }
}
