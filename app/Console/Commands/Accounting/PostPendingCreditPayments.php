<?php

namespace App\Console\Commands\Accounting;

use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Services\Accounting\CreditPaymentPostingService;
use Illuminate\Console\Command;

/**
 * READ-ONLY since 2026-10-15: the server no longer posts journals —
 * the device that performs a transaction posts it. This now only
 * counts what is still unposted; the posting described below no
 * longer happens.
 *
 * Two jobs in one, same shape as `accounting:post-pending-sales`:
 *
 * 1. Ongoing sweep — catches a repayment CreditPaymentPostingService's
 *    inline SyncProcessor hook missed (should be rare; unlike a sale there's
 *    nothing else this row depends on syncing first).
 * 2. One-off backfill — CreditPaymentPostingService didn't exist until now,
 *    so every already-live business has unposted repayment history back to
 *    its accounting_go_live_date. Safe to run repeatedly: postIfReady()'s
 *    own idempotency check skips anything already posted.
 *
 * Scheduled every 15 minutes (routes/console.php) once the initial backfill
 * has been run manually.
 */
class PostPendingCreditPayments extends Command
{
    protected $signature = 'accounting:post-pending-credit-payments';

    protected $description = 'Report (read-only) customer credit repayments left unposted — the server no longer posts';

    public function handle(): int
    {
        $businesses = Business::whereNotNull('accounting_go_live_date')->get();

        $attempted = 0;

        foreach ($businesses as $business) {
            $postedIds = JournalHeader::where('business_id', $business->id)
                ->where('source_type', 'credit_payment')
                ->pluck('source_id');

            $customerIds = Customer::where('business_id', $business->id)->pluck('id');

            $pending = CreditTransaction::where('type', 'repayment')
                ->whereIn('customer_id', $customerIds)
                ->whereDate('created_at', '>=', $business->accounting_go_live_date)
                ->whereNotIn('id', $postedIds)
                ->get();

            foreach ($pending as $creditTransaction) {
                $transDate = $creditTransaction->created_at->toDateString();

                $attempted++;
            }
        }

        $this->info("Found {$attempted} unposted credit payment(s) across {$businesses->count()} live business(es). The server no longer posts — post them on a device.");

        return self::SUCCESS;
    }
}
