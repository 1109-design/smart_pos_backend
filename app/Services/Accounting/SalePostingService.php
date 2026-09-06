<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 11b — turns a synced POS sale into a journal. Deliberately tolerant
 * of failure and partial data: a synced sale is the source of truth on its
 * own, so a posting problem must never block sync or corrupt the ledger —
 * it logs a warning and leaves the sale unposted (or, if the numbers came
 * out unbalanced, posted as an unresolved draft) for a later retry or
 * manual look. See `accounting:post-pending-sales` (routes/console.php) for
 * the sweep that catches whatever this misses on the first pass.
 *
 * Known simplification: COGS is computed from whatever `stock_movements`
 * rows (type='sale', reference_id=transaction) exist at posting time. These
 * normally sync in the same push as the transaction/items, but if they
 * arrive later, the sale can post with understated COGS. Revisit if that
 * proves to matter in practice.
 */
class SalePostingService
{
    public function __construct(private readonly JournalService $journals) {}

    public function postIfReady(Transaction $transaction): void
    {
        $business = Business::find($transaction->business_id);

        if (! $business?->accountingIsLive()) {
            return; // no go-live date set — see the accounting_go_live_date migration
        }

        $transDate = ($transaction->created_at ?? now())->toDateString();
        if ($transDate < $business->accounting_go_live_date->toDateString()) {
            return; // pre-dates the cutover — covered by opening balances instead, not auto-posted
        }

        if ($transaction->status === 'voided') {
            $this->handleVoid($transaction);

            return;
        }

        // 'layby' is deliberately excluded — the till doesn't deduct stock
        // for a layby until it's paid off in full, at which point its
        // status flips to 'completed' and this runs again for real.
        if (! in_array($transaction->status, ['completed', 'credit_sale', 'refunded', 'partial_refund'], true)) {
            return;
        }

        if ($this->existingJournal($transaction)) {
            return; // already posted, or already left as an unresolved draft
        }

        $items = TransactionItem::where('transaction_id', $transaction->id)->get();
        $payments = Payment::where('transaction_id', $transaction->id)->get();

        if ($items->isEmpty() || $payments->isEmpty()) {
            return; // not everything for this sale has synced yet
        }

        $this->post($transaction, $payments);
    }

    private function handleVoid(Transaction $transaction): void
    {
        $existing = $this->existingJournal($transaction);

        if (! $existing || $existing->status !== 'posted') {
            return; // nothing posted to reverse (never posted, or already reversed)
        }

        try {
            $this->journals->reverse($existing, null, 'Sale voided');
        } catch (Throwable $e) {
            Log::warning("Accounting: failed to reverse voided sale {$transaction->id}: {$e->getMessage()}");
        }
    }

    private function post(Transaction $transaction, $payments): void
    {
        $accounts = $this->resolveAccounts($transaction->business_id);

        if (! $accounts) {
            Log::warning("Accounting: no chart of accounts for business {$transaction->business_id} — skipping sale {$transaction->id}.");

            return;
        }

        try {
            $header = $this->journals->createDraft(
                $transaction->business_id,
                ($transaction->created_at ?? now())->toDateString(),
                'sale',
                $transaction->id,
                'Sale '.($transaction->sale_number ?? $transaction->id),
            );

            // Revenue folds in surcharge_total (real income, simple to treat
            // as revenue) but not deposit_total, which is refundable and
            // gets its own liability line — crediting it to Revenue would
            // overstate income.
            $revenue = (float) $transaction->subtotal - (float) $transaction->discount_total + (float) $transaction->surcharge_total;
            $tax = (float) $transaction->tax_total;
            $deposits = (float) $transaction->deposit_total;

            $this->addSignedLine($header, $accounts['revenue'], $revenue);
            $this->addSignedLine($header, $accounts['tax'], $tax);
            $this->addSignedLine($header, $accounts['deposits'], $deposits);

            $roundingTotal = 0.0;
            $paymentLines = [];

            foreach ($payments as $payment) {
                $account = $this->resolvePaymentAccount($payment, $accounts);
                $isReceivable = $account->control_type === 'receivable' && $transaction->customer_id;
                $key = $account->id.'|'.($isReceivable ? $transaction->customer_id : '');

                $paymentLines[$key] ??= [
                    'account' => $account,
                    'amount' => 0.0,
                    'party_type' => $isReceivable ? 'customer' : null,
                    'party_id' => $isReceivable ? $transaction->customer_id : null,
                ];
                $paymentLines[$key]['amount'] += (float) $payment->base_equivalent;
                $roundingTotal += (float) ($payment->rounding_adjustment ?? 0);
            }

            foreach ($paymentLines as $line) {
                // Debtor/bank/cash lines are debited by what was collected —
                // negative only for a refund transaction's own negative
                // base_equivalent, which correctly credits the account instead.
                $this->journals->addLine($header, [
                    'gl_account_id' => $line['account']->id,
                    'debit' => max(0, $line['amount']),
                    'credit' => max(0, -$line['amount']),
                    'party_type' => $line['party_type'],
                    'party_id' => $line['party_id'],
                ]);
            }

            // A positive rounding total means the till collected more than
            // the exact sale price (Dr Cash already reflects that extra, via
            // Payment.base_equivalent) — the excess is credited here as
            // income. A negative total debits this account instead, for the
            // shortfall the till collected less than the exact price.
            $this->addSignedLine($header, $accounts['rounding'], $roundingTotal);

            $cogs = (float) StockMovement::where('reference_id', $transaction->id)
                ->where('type', 'sale')
                ->get()
                ->sum(fn ($m) => abs((float) $m->quantity_change) * (float) ($m->running_avg_cost ?? 0));

            if ($cogs > 0.005) {
                $this->journals->addLine($header, ['gl_account_id' => $accounts['cogs']->id, 'debit' => $cogs]);
                $this->journals->addLine($header, ['gl_account_id' => $accounts['inventory']->id, 'credit' => $cogs]);
            }

            if (! $this->journals->isBalanced($header)) {
                Log::warning(
                    "Accounting: sale {$transaction->id} does not balance — left as unposted draft ".
                    "{$header->journal_number} for manual review (split-payment change-owed cases are the ".
                    'likely cause — see SalePostingService doc comment).'
                );

                return;
            }

            $this->journals->post($header);
        } catch (Throwable $e) {
            Log::warning("Accounting: failed to post sale {$transaction->id}: {$e->getMessage()}");
        }
    }

    /**
     * Adds a line whose sign decides debit vs credit — a positive amount
     * credits the account (revenue/tax/deposits all grow on the credit
     * side), a negative amount debits it instead.
     */
    private function addSignedLine(JournalHeader $header, GlAccount $account, float $amount): void
    {
        if (abs($amount) <= 0.005) {
            return;
        }

        $this->journals->addLine($header, [
            'gl_account_id' => $account->id,
            'debit' => max(0, -$amount),
            'credit' => max(0, $amount),
        ]);
    }

    private function resolvePaymentAccount(Payment $payment, array $accounts): GlAccount
    {
        $method = strtolower($payment->method ?? '');

        return match (true) {
            str_contains($method, 'credit') => $accounts['receivable'],
            str_contains($method, 'mobile'), str_contains($method, 'ecocash') => $accounts['mobile'],
            str_contains($method, 'card'), str_contains($method, 'bank'), str_contains($method, 'swipe') => $accounts['bank'],
            default => $accounts['cash'],
        };
    }

    private function existingJournal(Transaction $transaction): ?JournalHeader
    {
        return JournalHeader::where('business_id', $transaction->business_id)
            ->where('source_type', 'sale')
            ->where('source_id', $transaction->id)
            ->first();
    }

    /**
     * @return array<string, GlAccount>|null
     */
    private function resolveAccounts(string $businessId): ?array
    {
        $codes = [
            'cash' => '1000', 'bank' => '1010', 'mobile' => '1020', 'receivable' => '1100',
            'inventory' => '1200', 'deposits' => '2020', 'tax' => '2030', 'revenue' => '4000',
            'cogs' => '5000', 'rounding' => '6060',
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
