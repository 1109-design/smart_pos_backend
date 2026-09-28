<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a synced invoice payment into a journal — the invoice-side
 * counterpart of CreditPaymentPostingService (POS on-account repayments).
 * Until this existed, an invoice payment only ever touched the legacy
 * CreditTransactions/Customer.credit_balance ledger, invisible to
 * PartyLedgerService's GL-derived AR statement/aging. Same
 * tolerant-of-failure, never-blocks-sync philosophy as every other posting
 * service here: a synced invoice_payments row is the source of truth on
 * its own.
 *
 * Unlike SalePostingService/CreditPaymentPostingService (which only ever
 * post the base-currency equivalent), this one also records the original
 * tendered amount/rate on the tender-account line via
 * foreign_debit/currency_code/exchange_rate — JournalService::addLine()
 * already accepted these, no posting service had ever actually used them.
 * The Accounts Receivable line stays a pure base-currency figure, since the
 * control account itself isn't tracked per-currency.
 *
 * See `accounting:post-pending-invoice-payments` for the sweep that both
 * catches whatever this misses on the first pass and backfills every
 * already-live business's pre-existing unposted payment history.
 */
class InvoicePaymentPostingService
{
    public function __construct(private readonly JournalService $journals) {}

    /**
     * @param  bool  $viaSweep  True only from the pending-invoice-payments
     *                          sweep's grace-period fallback for a
     *                          client_gl_posting_enabled_at business — see
     *                          SalePostingService::postIfReady()'s
     *                          identical parameter.
     */
    public function postIfReady(InvoicePayment $payment, bool $viaSweep = false): void
    {
        $invoice = Invoice::find($payment->invoice_id);
        $business = $invoice ? Business::find($invoice->business_id) : null;

        if (! $business?->accountingIsLive()) {
            return;
        }

        $transDate = ($payment->paid_at ?? now())->toDateString();
        if ($transDate < $business->accounting_go_live_date->toDateString()) {
            return;
        }

        if (! $viaSweep && $business->postsFromClientFor($transDate)) {
            return;
        }

        if (! $invoice->customer_id) {
            return; // no party to post the receivable line against
        }

        if ($this->alreadyPosted($business->id, $payment->id)) {
            return;
        }

        $amount = abs((float) $payment->base_equivalent);
        if ($amount <= 0.005) {
            return;
        }

        $accounts = $this->resolveAccounts($business->id);
        if (! $accounts) {
            Log::warning("Accounting: no chart of accounts for business {$business->id} — skipping invoice payment {$payment->id}.");

            return;
        }

        $tenderAccount = $this->resolveTenderAccount($payment->method, $payment->bank_account_id, $accounts);
        $isForeign = $payment->currency_code && $payment->currency_code !== $business->currency_code;

        try {
            $header = $this->journals->createDraft(
                $business->id,
                $transDate,
                'invoice_payment',
                $payment->id,
                "Invoice payment — {$invoice->invoice_number}",
            );

            $this->journals->addLine($header, array_merge([
                'gl_account_id' => $tenderAccount->id,
                'debit' => $amount,
            ], $isForeign ? [
                'currency_code' => $payment->currency_code,
                'exchange_rate' => (float) $payment->exchange_rate_used,
                'foreign_debit' => (float) $payment->amount,
            ] : []));

            $this->journals->addLine($header, [
                'gl_account_id' => $accounts['receivable']->id,
                'credit' => $amount,
                'party_type' => 'customer',
                'party_id' => $invoice->customer_id,
            ]);

            $this->journals->post($header);
        } catch (Throwable $e) {
            Log::warning("Accounting: failed to post invoice payment {$payment->id}: {$e->getMessage()}");
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

    private function alreadyPosted(string $businessId, string $paymentId): bool
    {
        return JournalHeader::where('business_id', $businessId)
            ->where('source_type', 'invoice_payment')
            ->where('source_id', $paymentId)
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
