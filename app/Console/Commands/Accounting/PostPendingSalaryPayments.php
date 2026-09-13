<?php

namespace App\Console\Commands\Accounting;

use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\SalaryPayment;
use App\Services\Accounting\SalaryPostingService;
use Illuminate\Console\Command;

/**
 * Two jobs in one, same shape as `accounting:post-pending-sales`:
 *
 * 1. Ongoing sweep — catches a salary payment SalaryPostingService's inline
 *    SyncProcessor hook missed (should be rare).
 * 2. One-off backfill — SalaryPostingService had no cutover gate at all
 *    until Flutter gained its own client-side posting, so every already
 *    client-cut-over business needs this to pick up straggler payments an
 *    old app version posted only locally without ever telling the server,
 *    or that landed before the client-posting code shipped.
 *
 * Scheduled every 15 minutes (routes/console.php) once the initial backfill
 * has been run manually.
 */
class PostPendingSalaryPayments extends Command
{
    private const GRACE_PERIOD_HOURS = 1;

    protected $signature = 'accounting:post-pending-salary-payments';

    protected $description = 'Post accounting entries for salary payments left unposted (sweep + one-off backfill)';

    public function handle(SalaryPostingService $posting): int
    {
        $businesses = Business::whereNotNull('accounting_go_live_date')->get();

        $attempted = 0;

        foreach ($businesses as $business) {
            $postedIds = JournalHeader::where('business_id', $business->id)
                ->where('source_type', 'salary_payment')
                ->pluck('source_id');

            $pending = SalaryPayment::where('business_id', $business->id)
                ->whereDate('paid_at', '>=', $business->accounting_go_live_date)
                ->whereNotIn('id', $postedIds)
                ->get();

            foreach ($pending as $payment) {
                $transDate = ($payment->paid_at ?? now())->toDateString();
                $viaSweep = $business->postsFromClientFor($transDate)
                    && ($payment->paid_at ?? now())->lte(now()->subHours(self::GRACE_PERIOD_HOURS));

                $posting->recordPayment($payment, $viaSweep);
                $attempted++;
            }
        }

        $this->info("Attempted {$attempted} pending salary payment(s) across {$businesses->count()} live business(es).");

        return self::SUCCESS;
    }
}
