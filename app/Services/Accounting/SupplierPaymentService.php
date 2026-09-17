<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Purchasing & Cash Vault Blueprint, part B — Dr Accounts Payable / Cr Cash
 * or Bank (or a specifically tagged BankAccount's own GL line). Not tied to
 * a specific invoice: matches the same "one running balance, FIFO-aged"
 * simplification Phase 11c's PartyLedgerService already uses for debtors —
 * the payment reduces the supplier's overall balance, and aging settles the
 * oldest outstanding charge first. Account codes resolved via
 * AccountRoleMappingService — nothing hardcoded.
 *
 * Two entry points, same split as InvoicePaymentPostingService/
 * CreditPaymentPostingService vs. their sync-triggered path:
 * - `recordPayment()` — the original, synchronous BackOffice action: a
 *   person is waiting, so this creates the row AND posts immediately,
 *   throwing on failure rather than silently skipping.
 * - `postIfReady()` — the new Flutter-first counterpart's server-side twin:
 *   given a payment row a device already created and synced up, posts its
 *   GL journal if not already posted, tolerant of failure like every other
 *   sync-triggered posting service, gated by the usual cutover check.
 */
class SupplierPaymentService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly AccountRoleMappingService $mappings,
    ) {}

    public function recordPayment(
        string $businessId,
        string $supplierId,
        float $amount,
        string $paymentDate,
        string $method = 'cash',
        ?string $reference = null,
        ?string $userId = null,
        ?string $bankAccountId = null,
    ): SupplierPayment {
        $business = Business::find($businessId);
        if (! $business?->accountingIsLive()) {
            throw new RuntimeException('Accounting has not been switched on for this business yet.');
        }

        $cashOrBank = $this->resolveFundingAccount($businessId, $method, $bankAccountId);
        $accountsPayable = $this->mappings->resolve($businessId, 'accounts_payable');

        return DB::transaction(function () use ($businessId, $supplierId, $amount, $paymentDate, $method, $reference, $userId, $bankAccountId, $cashOrBank, $accountsPayable) {
            $payment = SupplierPayment::create([
                'business_id' => $businessId,
                'supplier_id' => $supplierId,
                'amount' => $amount,
                'payment_date' => $paymentDate,
                'method' => $method,
                'reference' => $reference,
                'recorded_by_user_id' => $userId,
                'bank_account_id' => $bankAccountId,
            ]);

            $this->postJournal($payment, $accountsPayable, $cashOrBank, $amount);

            return $payment;
        });
    }

    /**
     * @param  bool  $viaSweep  True only from the pending-supplier-payments
     *                          sweep's grace-period fallback for a
     *                          client_gl_posting_enabled_at business — see
     *                          SalePostingService::postIfReady()'s
     *                          identical parameter.
     */
    public function postIfReady(SupplierPayment $payment, bool $viaSweep = false): void
    {
        if (JournalHeader::where('source_type', 'supplier_payment')->where('source_id', $payment->id)->exists()) {
            return; // already posted — idempotency guard
        }

        $business = Business::find($payment->business_id);
        if (! $business?->accountingIsLive()) {
            return;
        }

        $transDate = $payment->payment_date?->toDateString() ?? now()->toDateString();
        if ($transDate < $business->accounting_go_live_date->toDateString()) {
            return;
        }

        if (! $viaSweep && $business->postsFromClientFor($transDate)) {
            return;
        }

        $amount = round((float) $payment->amount, 4);
        if ($amount <= 0.005) {
            return;
        }

        try {
            $cashOrBank = $this->resolveFundingAccount($payment->business_id, $payment->method, $payment->bank_account_id);
            $accountsPayable = $this->mappings->resolve($payment->business_id, 'accounts_payable');

            $this->postJournal($payment, $accountsPayable, $cashOrBank, $amount);
        } catch (Throwable $e) {
            Log::warning("Accounting: failed to post supplier payment {$payment->id}: {$e->getMessage()}");
        }
    }

    private function postJournal(SupplierPayment $payment, GlAccount $accountsPayable, GlAccount $cashOrBank, float $amount): void
    {
        $header = $this->journals->createDraft(
            $payment->business_id,
            $payment->payment_date?->toDateString() ?? now()->toDateString(),
            'supplier_payment',
            $payment->id,
            'Payment to supplier'.($payment->reference ? " ({$payment->reference})" : ''),
        );

        $this->journals->addLine($header, [
            'gl_account_id' => $accountsPayable->id,
            'debit' => $amount,
            'party_type' => 'supplier',
            'party_id' => $payment->supplier_id,
        ]);
        $this->journals->addLine($header, ['gl_account_id' => $cashOrBank->id, 'credit' => $amount]);

        $this->journals->post($header);
    }

    private function resolveFundingAccount(string $businessId, string $method, ?string $bankAccountId): GlAccount
    {
        $role = $method === 'bank' ? 'default_bank' : 'default_cash';
        $account = $this->mappings->resolve($businessId, $role);

        return $role === 'default_bank' ? $this->resolveBankAccount($bankAccountId, $account) : $account;
    }

    /**
     * See SalePostingService::resolveBankAccount() — identical fallback
     * behavior.
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
}
