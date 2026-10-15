<?php

namespace App\Console\Commands\Accounting;

use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\SalaryPayment;
use App\Services\Accounting\SalaryPostingService;
use Illuminate\Console\Command;

/**
 * READ-ONLY since 2026-10-15: the server no longer posts journals —
 * the device that performs a transaction posts it. This now only
 * counts what is still unposted; the posting described below no
 * longer happens.
 *
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
    protected $signature = 'accounting:post-pending-salary-payments';

    protected $description = 'Report (read-only) salary payments left unposted — the server no longer posts';

    public function handle(): int
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

                $attempted++;
            }
        }

        $this->info("Found {$attempted} unposted salary payment(s) across {$businesses->count()} live business(es). The server no longer posts — post them on a device.");

        return self::SUCCESS;
    }
}
