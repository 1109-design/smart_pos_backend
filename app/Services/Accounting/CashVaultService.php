<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\BankAccount;
use App\Models\Business;
use RuntimeException;

/**
 * Purchasing & Cash Vault Blueprint, part C — the safe between the till
 * drawer and the bank. Deliberately just a GL account ("Cash Vault"), not a
 * parallel ledger table: its balance is derived the same way every other
 * account's is, and its activity is just that account's own general_ledger
 * rows. Manual BackOffice actions, same as supplier invoices/payments —
 * throws rather than silently skipping, since a person is waiting.
 *
 * Deliberately blended in base currency, not a true per-currency balance:
 * SalePostingService only ever posts a sale's base-currency equivalent to
 * Cash (Payment.base_equivalent), never a true multi-currency breakdown of
 * what physically came in — so a "how many physical USD notes vs ZWG notes
 * should be in the vault" figure would be false precision built on data
 * that was never captured. If that ever matters, it has to start with
 * SalePostingService tracking real per-currency cash inflow, not here.
 */
class CashVaultService
{
    private const VAULT_CODE = '1005';

    private const VARIANCE_ACCOUNT = ['code' => '6065', 'name' => 'Cash Vault Variance'];

    public function __construct(
        private readonly JournalService $journals,
        private readonly ChartOfAccountsSeeder $chartSeeder,
        private readonly AccountActivityService $activityService,
    ) {}

    public function balance(string $businessId): float
    {
        return $this->vaultAccount($businessId)->balance();
    }

    /**
     * The vault's own equivalent of PartyLedgerService::statement(), but
     * for a plain GL account rather than a customer/supplier — see
     * AccountActivityService, which this just points at the vault account.
     *
     * @return array<int, array{date: string, description: ?string, debit: float, credit: float, running_balance: float}>
     */
    public function activity(string $businessId): array
    {
        return $this->activityService->activity($businessId, $this->vaultAccount($businessId));
    }

    public function recordTillDrop(string $businessId, float $amount, string $date, ?string $note, ?string $userId): void
    {
        $this->assertLive($businessId);

        $vault = $this->vaultAccount($businessId);
        $cash = $this->requireAccount($businessId, '1000', 'Cash');

        $header = $this->journals->createDraft($businessId, $date, 'cash_vault_drop', null, $note ?: 'Till drop to vault');
        $this->journals->addLine($header, ['gl_account_id' => $vault->id, 'debit' => $amount]);
        $this->journals->addLine($header, ['gl_account_id' => $cash->id, 'credit' => $amount]);
        $this->journals->post($header, $userId);
    }

    public function recordBankDeposit(string $businessId, float $amount, string $date, ?string $note, ?string $userId, ?string $bankAccountId = null): void
    {
        $this->assertLive($businessId);

        $vault = $this->vaultAccount($businessId);
        $bank = $this->resolveBankAccount($bankAccountId, $this->requireAccount($businessId, '1010', 'Bank'));

        $header = $this->journals->createDraft($businessId, $date, 'cash_vault_deposit', null, $note ?: 'Vault banked');
        $this->journals->addLine($header, ['gl_account_id' => $bank->id, 'debit' => $amount]);
        $this->journals->addLine($header, ['gl_account_id' => $vault->id, 'credit' => $amount]);
        $this->journals->post($header, $userId);
    }

    /**
     * When a specific bank account was chosen for the deposit, post against
     * that account's own GL line instead of the single generic Bank (1010)
     * account — see BankAccountService. Falls back to $default when no bank
     * account was specified, or it can't be resolved.
     */
    private function resolveBankAccount(?string $bankAccountId, GlAccount $default): GlAccount
    {
        if (! $bankAccountId) {
            return $default;
        }

        $bankAccount = BankAccount::find($bankAccountId);
        $glAccount = $bankAccount ? GlAccount::find($bankAccount->gl_account_id) : null;

        return $glAccount ?? $default;
    }

    /**
     * Reconciles a physical count of the safe against what the ledger says
     * should be there, posting the difference to Cash Vault Variance —
     * same sign convention as Phase 5's Cash Rounding Variance: found more
     * than expected credits it (a gain), a shortfall debits it (a loss).
     * Returns the signed variance actually posted (0 if none).
     */
    public function recordCount(string $businessId, float $countedAmount, string $date, ?string $userId): float
    {
        $this->assertLive($businessId);

        $expected = $this->balance($businessId);
        $variance = round($countedAmount - $expected, 4);

        if (abs($variance) <= 0.005) {
            return 0.0;
        }

        $vault = $this->vaultAccount($businessId);
        $varianceAccount = $this->chartSeeder->ensureAccount($businessId, 'Expenses', 'Other Expenses', self::VARIANCE_ACCOUNT);

        $header = $this->journals->createDraft(
            $businessId,
            $date,
            'cash_vault_count',
            null,
            "Vault count: expected {$expected}, counted {$countedAmount}",
        );

        if ($variance > 0) {
            $this->journals->addLine($header, ['gl_account_id' => $vault->id, 'debit' => $variance]);
            $this->journals->addLine($header, ['gl_account_id' => $varianceAccount->id, 'credit' => $variance]);
        } else {
            $this->journals->addLine($header, ['gl_account_id' => $varianceAccount->id, 'debit' => abs($variance)]);
            $this->journals->addLine($header, ['gl_account_id' => $vault->id, 'credit' => abs($variance)]);
        }

        $this->journals->post($header, $userId);

        return $variance;
    }

    private function vaultAccount(string $businessId): GlAccount
    {
        return $this->chartSeeder->ensureAccount(
            $businessId,
            'Assets',
            'Current Assets',
            ['code' => self::VAULT_CODE, 'name' => 'Cash Vault', 'must_be_positive' => true],
        );
    }

    private function requireAccount(string $businessId, string $code, string $label): GlAccount
    {
        $account = GlAccount::where('business_id', $businessId)->where('code', $code)->first();

        if (! $account) {
            throw new RuntimeException("Chart of accounts is missing {$label}.");
        }

        return $account;
    }

    private function assertLive(string $businessId): void
    {
        $business = Business::find($businessId);
        if (! $business?->accountingIsLive()) {
            throw new RuntimeException('Accounting has not been switched on for this business yet.');
        }
    }
}
