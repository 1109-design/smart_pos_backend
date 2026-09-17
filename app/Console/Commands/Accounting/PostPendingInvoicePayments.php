<?php

namespace App\Console\Commands\Accounting;

use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\Accounting\InvoicePaymentPostingService;
use Illuminate\Console\Command;

/**
 * Two jobs in one, same shape as `accounting:post-pending-credit-payments`:
 *
 * 1. Ongoing sweep — catches a payment InvoicePaymentPostingService's
 *    inline SyncProcessor hook missed.
 * 2. One-off backfill — InvoicePaymentPostingService didn't exist until
 *    now, so every already-live business has unposted invoice-payment
 *    history back to its accounting_go_live_date. Safe to run repeatedly:
 *    postIfReady()'s own idempotency check skips anything already posted.
 *
 * Scheduled every 15 minutes (routes/console.php).
 */
class PostPendingInvoicePayments extends Command
{
    private const GRACE_PERIOD_HOURS = 1;

    protected $signature = 'accounting:post-pending-invoice-payments';

    protected $description = 'Post accounting entries for invoice payments left unposted (sweep + one-off backfill)';

    public function handle(InvoicePaymentPostingService $posting): int
    {
        $businesses = Business::whereNotNull('accounting_go_live_date')->get();

        $attempted = 0;

        foreach ($businesses as $business) {
            $postedIds = JournalHeader::where('business_id', $business->id)
                ->where('source_type', 'invoice_payment')
                ->pluck('source_id');

            $invoiceIds = Invoice::where('business_id', $business->id)->pluck('id');

            $pending = InvoicePayment::whereIn('invoice_id', $invoiceIds)
                ->whereDate('paid_at', '>=', $business->accounting_go_live_date)
                ->whereNotIn('id', $postedIds)
                ->get();

            foreach ($pending as $payment) {
                $transDate = $payment->paid_at->toDateString();
                $viaSweep = $business->postsFromClientFor($transDate)
                    && $payment->paid_at->lte(now()->subHours(self::GRACE_PERIOD_HOURS));

                $posting->postIfReady($payment, $viaSweep);
                $attempted++;
            }
        }

        $this->info("Attempted {$attempted} pending invoice payment(s) across {$businesses->count()} live business(es).");

        return self::SUCCESS;
    }
}
