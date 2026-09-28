<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;

/**
 * A chronological activity list with a running balance for one plain GL
 * account — the "cash book" concept, generalized. Originally lived only
 * inside `CashVaultService::activity()` (vault-specific); extracted here so
 * any account (a named bank account, the vault, anything else) can get the
 * same view without duplicating the query. No aging/party concept applies
 * here — that's `PartyLedgerService`'s job for a customer/supplier.
 */
class AccountActivityService
{
    /**
     * @return array<int, array{id: string, date: string, description: ?string, debit: float, credit: float, running_balance: float, reconciled_at: ?string, bank_reconciliation_id: ?string}>
     */
    public function activity(string $businessId, GlAccount $account): array
    {
        $running = 0.0;
        $rows = [];

        $lines = GeneralLedgerEntry::with('header')
            ->where('business_id', $businessId)
            ->where('gl_account_id', $account->id)
            ->orderBy('trans_date')
            ->orderBy('created_at')
            ->get();

        foreach ($lines as $line) {
            $running += (float) $line->debit - (float) $line->credit;
            $rows[] = [
                'id' => $line->id,
                'date' => $line->trans_date->toDateString(),
                'description' => $line->description ?? $line->header?->description,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'running_balance' => round($running, 4),
                'reconciled_at' => $line->reconciled_at?->toIso8601String(),
                'bank_reconciliation_id' => $line->bank_reconciliation_id,
            ];
        }

        return $rows;
    }
}
