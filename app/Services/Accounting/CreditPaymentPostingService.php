<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\CreditTransaction;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a synced customer credit repayment into a journal — the missing
 * half of credit-sale accounting. SalePostingService posts the AR debit
 * when a credit sale happens; until this existed, NOTHING ever posted the
 * matching credit when the customer paid it back, so
 * PartyLedgerService's GL-derived AR balance only ever grew. Same
 * tolerant-of-failure, never-blocks-sync philosophy as SalePostingService:
 * a synced credit_transactions row is the source of truth on its own.
 *
 * See `accounting:post-pending-credit-payments` for the sweep that both
 * catches whatever this misses on the first pass AND backfills every
 * already-live business's pre-existing unposted repayment history.
 */
class CreditPaymentPostingService
{
    public function __construct(private readonly JournalService $journals) {}

    /**
     * @param  bool  $viaSweep  True only from the pending-credit-payments
     *                          sweep's grace-period fallback for a
     *                          client_gl_posting_enabled_at business — see
     *                          SalePostingService::postIfReady()'s
     *                          identical parameter.
     */
    public function postIfReady(CreditTransaction $creditTransaction, bool $viaSweep = false): void
    {
        if ($creditTransaction->type !== 'repayment') {
            return;
        }

        $customer = Customer::find($creditTransaction->customer_id);
        $business = $customer ? Business::find($customer->business_id) : null;

        if (! $business?->accountingIsLive()) {
            return;
        }

        $transDate = ($creditTransaction->created_at ?? now())->toDateString();
        if ($transDate < $business->accounting_go_live_date->toDateString()) {
            return;
        }

        if (! $viaSweep && $business->postsFromClientFor($transDate)) {
            return;
        }

        if ($this->alreadyPosted($business->id, $creditTransaction->id)) {
            return;
        }

        $amount = abs((float) $creditTransaction->amount);
        if ($amount <= 0.005) {
            return;
        }

        $accounts = $this->resolveAccounts($business->id);
        if (! $accounts) {
            Log::warning("Accounting: no chart of accounts for business {$business->id} — skipping credit payment {$creditTransaction->id}.");

            return;
        }

        $tenderAccount = $this->resolveTenderAccount($creditTransaction->method, $creditTransaction->bank_account_id, $accounts);

        try {
            $header = $this->journals->createDraft(
                $business->id,
                $transDate,
                'credit_payment',
                $creditTransaction->id,
                'Credit repayment'.($creditTransaction->reference ? " ({$creditTransaction->reference})" : ''),
            );

            $this->journals->addLine($header, [
                'gl_account_id' => $tenderAccount->id,
                'debit' => $amount,
            ]);
            $this->journals->addLine($header, [
                'gl_account_id' => $accounts['receivable']->id,
                'credit' => $amount,
                'party_type' => 'customer',
                'party_id' => $customer->id,
            ]);

            $this->journals->post($header);
        } catch (Throwable $e) {
            Log::warning("Accounting: failed to post credit payment {$creditTransaction->id}: {$e->getMessage()}");
        }
    }

    private function resolveTenderAccount(?string $method, ?string $bankAccountId, array $accounts): GlAccount
    {
        $method = strtolower($method ?? '');

        return match (true) {
            str_contains($method, 'mobile'), str_contains($method, 'ecocash') => $accounts['mobile'],
            str_contains($method, 'card'), str_contains($method, 'bank'), str_contains($method, 'swipe') => $this->resolveBankAccount($bankAccountId, $accounts['bank']),
            default => $accounts['cash'],
        };
    }

    /**
     * See SalePostingService::resolveBankAccount() — identical fallback
     * behavior, duplicated rather than shared since each posting service
     * already keeps its own resolveTenderAccount()/resolvePaymentAccount().
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

    private function alreadyPosted(string $businessId, string $creditTransactionId): bool
    {
        return JournalHeader::where('business_id', $businessId)
            ->where('source_type', 'credit_payment')
            ->where('source_id', $creditTransactionId)
            ->where('status', '!=', 'reversed')
            ->exists();
    }

    /**
     * @return array<string, GlAccount>|null
     */
    private function resolveAccounts(string $businessId): ?array
    {
        $codes = [
            'cash' => '1000', 'bank' => '1010', 'mobile' => '1020', 'receivable' => '1100',
        ];

        $accounts = GlAccount::where('business_id', $businessId)
            ->whereIn('code', array_values($codes))
            ->get()
            ->keyBy('code');

        $resolved = [];
        foreach ($codes as $key => $code) {
            if (! isset($accounts[$code])) {
                return null;
            }
            $resolved[$key] = $accounts[$code];
        }

        return $resolved;
    }
}
