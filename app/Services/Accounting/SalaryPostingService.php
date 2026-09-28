<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\SalaryPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a payroll disbursement to the general ledger: Dr the 'salary_expense'
 * role / Cr whichever cash/bank/mobile-money role the payment method implies
 * (or a specifically tagged BankAccount's own GL line) — see
 * AccountRoleMappingService, which replaced this service's own hardcoded GL
 * codes. Cheque payments resolve to the 'default_bank' role — a cheque is a
 * bank instrument, not a separate clearing account this chart tracks.
 *
 * Valued at base_equivalent (already converted at the rate recorded when
 * the payment was made on the till), same as every other multi-currency
 * posting service in this app — see SalePostingService.
 *
 * Unlike every other posting service here, this one had NO client-side
 * counterpart until Flutter gained `salary_posting_service.dart` — until
 * then, a Flutter-recorded salary payment only ever posted its GL journal
 * here, triggered from SyncProcessor. Now gated by `postsFromClientFor()`/
 * `$viaSweep` exactly like SalePostingService, so a business cut over to
 * client-side posting doesn't double-post. See
 * `accounting:post-pending-salary-payments` for the sweep/backfill.
 */
class SalaryPostingService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly AccountRoleMappingService $mappings,
    ) {}

    /**
     * @param  bool  $viaSweep  True only from the pending-salary-payments
     *                          sweep's grace-period fallback for a
     *                          client_gl_posting_enabled_at business — see
     *                          SalePostingService::postIfReady()'s
     *                          identical parameter.
     */
    public function recordPayment(SalaryPayment $payment, bool $viaSweep = false): void
    {
        if (JournalHeader::where('source_type', 'salary_payment')->where('source_id', $payment->id)->exists()) {
            return; // already processed — sync can redeliver the same record
        }

        $business = Business::find($payment->business_id);
        if (! $business?->accountingIsLive()) {
            return;
        }

        $transDate = $payment->paid_at?->toDateString() ?? now()->toDateString();
        if ($transDate < $business->accounting_go_live_date->toDateString()) {
            return;
        }

        if (! $viaSweep && $business->postsFromClientFor($transDate)) {
            return;
        }

        $amount = round((float) $payment->base_equivalent, 4);
        if ($amount <= 0.005) {
            return;
        }

        try {
            DB::transaction(function () use ($payment, $transDate, $amount) {
                $wages = $this->mappings->resolve($payment->business_id, 'salary_expense');
                $funding = $this->resolveFundingAccount($payment->business_id, $payment->payment_method, $payment->bank_account_id);

                $header = $this->journals->createDraft(
                    $payment->business_id,
                    $transDate,
                    'salary_payment',
                    $payment->id,
                    'Salary payment — '.$payment->period,
                );
                $this->journals->addLine($header, ['gl_account_id' => $wages->id, 'debit' => $amount]);
                $this->journals->addLine($header, ['gl_account_id' => $funding->id, 'credit' => $amount]);
                $this->journals->post($header);
            });
        } catch (Throwable $e) {
            Log::warning("Accounting: failed to post salary payment {$payment->id}: {$e->getMessage()}");
        }
    }

    private function resolveFundingAccount(string $businessId, string $method, ?string $bankAccountId): GlAccount
    {
        $role = match (true) {
            $method === 'mobile_money' => 'default_mobile_money',
            $method === 'bank_transfer' || $method === 'cheque' => 'default_bank',
            default => 'default_cash',
        };

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
