<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\BankReconciliation;
use App\Models\SyncRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ticks a bank account's own cash-book lines (general_ledger rows against
 * its gl_account_id) off against a real bank statement — a session
 * (BankReconciliation) tracks the target statement balance, and each ticked
 * line is tagged reconciled_at/bank_reconciliation_id directly on its
 * general_ledger row (see JournalService::buildLedgerEntryPayload()'s doc
 * comment for why every republish must resend every field).
 *
 * Manual tick-off only — no statement file import or auto-matching. See the
 * Bank Reconciliation plan's "explicitly deferred" section.
 */
class BankReconciliationService
{
    public function __construct(private readonly JournalService $journals) {}

    /**
     * Returns the bank account's existing in_progress session if one
     * exists, otherwise starts a new one.
     */
    public function startOrResume(
        string $businessId,
        string $bankAccountId,
        string $statementDate,
        float $statementBalance,
        string $userId,
    ): BankReconciliation {
        $existing = BankReconciliation::where('business_id', $businessId)
            ->where('bank_account_id', $bankAccountId)
            ->where('status', 'in_progress')
            ->first();

        if ($existing) {
            return $existing;
        }

        $reconciliation = BankReconciliation::create([
            'business_id' => $businessId,
            'bank_account_id' => $bankAccountId,
            'statement_date' => $statementDate,
            'statement_balance' => $statementBalance,
            'status' => 'in_progress',
            'started_by_user_id' => $userId,
            'started_at' => now(),
        ]);

        $this->publish($reconciliation);

        return $reconciliation;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function unreconciledLines(GlAccount $account, BankReconciliation $reconciliation): array
    {
        $activity = app(AccountActivityService::class)->activity($account->business_id, $account);

        return array_values(array_filter(
            $activity,
            fn (array $row) => $row['reconciled_at'] === null && $row['date'] <= $reconciliation->statement_date->toDateString(),
        ));
    }

    /**
     * Sum of debit-credit for every reconciled line up to (and including)
     * $asOfDate — derived, never stored, same convention as
     * GlAccount::balance()/JournalService's account balance helpers.
     */
    public function clearedBalance(GlAccount $account, string $asOfDate): float
    {
        $totals = GeneralLedgerEntry::where('gl_account_id', $account->id)
            ->whereNotNull('reconciled_at')
            ->where('trans_date', '<=', $asOfDate)
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        return round((float) $totals->total_debit - (float) $totals->total_credit, 4);
    }

    public function toggleLine(BankReconciliation $reconciliation, GlAccount $account, string $entryId, bool $cleared): void
    {
        throw_unless($reconciliation->status === 'in_progress', new RuntimeException(
            'This reconciliation is already closed — reopen a new one to make changes.'
        ));

        $entry = GeneralLedgerEntry::where('id', $entryId)->firstOrFail();

        throw_unless($entry->gl_account_id === $account->id, new RuntimeException(
            'That entry does not belong to this bank account.'
        ));

        $entry->update([
            'reconciled_at' => $cleared ? now() : null,
            'bank_reconciliation_id' => $cleared ? $reconciliation->id : null,
        ]);

        $this->journals->republishLedgerEntry($entry->fresh());
    }

    /**
     * Locks the session once the cleared balance matches the statement
     * balance within the same 0.005 tolerance CashVaultService::recordCount()
     * already uses elsewhere.
     */
    public function complete(BankReconciliation $reconciliation, GlAccount $account, string $userId): BankReconciliation
    {
        throw_unless($reconciliation->status === 'in_progress', new RuntimeException(
            "This reconciliation is already {$reconciliation->status}."
        ));

        $cleared = $this->clearedBalance($account, $reconciliation->statement_date->toDateString());
        $diff = round($cleared - (float) $reconciliation->statement_balance, 4);

        throw_if(abs($diff) > 0.005, new RuntimeException(
            "Cleared balance ({$cleared}) doesn't match the statement balance ({$reconciliation->statement_balance}) — difference of {$diff}."
        ));

        $reconciliation->update([
            'status' => 'completed',
            'completed_by_user_id' => $userId,
            'completed_at' => now(),
        ]);

        $this->publish($reconciliation->fresh());

        return $reconciliation->fresh();
    }

    /**
     * Abandons an in_progress session — every line ticked under it is
     * untagged (freed back into the unreconciled pool for the next
     * session), and the session itself is marked cancelled rather than
     * deleted, preserving the audit trail.
     */
    public function cancel(BankReconciliation $reconciliation): BankReconciliation
    {
        throw_unless($reconciliation->status === 'in_progress', new RuntimeException(
            "Only an in-progress reconciliation can be cancelled — this one is {$reconciliation->status}."
        ));

        DB::transaction(function () use ($reconciliation) {
            $entries = GeneralLedgerEntry::where('bank_reconciliation_id', $reconciliation->id)->get();
            foreach ($entries as $entry) {
                $entry->update(['reconciled_at' => null, 'bank_reconciliation_id' => null]);
                $this->journals->republishLedgerEntry($entry->fresh());
            }

            $reconciliation->update(['status' => 'cancelled']);
        });

        $this->publish($reconciliation->fresh());

        return $reconciliation->fresh();
    }

    private function publish(BankReconciliation $reconciliation): void
    {
        SyncRecord::create([
            'business_id' => $reconciliation->business_id,
            'table_name' => 'bank_reconciliations',
            'record_uuid' => $reconciliation->id,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $reconciliation->business_id,
                'bank_account_id' => $reconciliation->bank_account_id,
                'statement_date' => $reconciliation->statement_date->toDateString(),
                'statement_balance' => (float) $reconciliation->statement_balance,
                'status' => $reconciliation->status,
                'started_by_user_id' => $reconciliation->started_by_user_id,
                'started_at' => $reconciliation->started_at->toIso8601String(),
                'completed_by_user_id' => $reconciliation->completed_by_user_id,
                'completed_at' => $reconciliation->completed_at?->toIso8601String(),
            ],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
