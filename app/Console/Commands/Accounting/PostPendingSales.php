<?php

namespace App\Console\Commands\Accounting;

use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Transaction;
use App\Services\Accounting\SalePostingService;
use Illuminate\Console\Command;

/**
 * Catches whatever SyncProcessor's inline hooks missed on the first pass —
 * mainly a sale whose items/payments hadn't all synced yet when its
 * transaction row first landed (see SalePostingService's doc comment).
 * Scheduled every 15 minutes (routes/console.php), same cadence as ZIMRA's
 * own retry sweep.
 *
 * Only ever looks at businesses with accounting_go_live_date set, and only
 * at their transactions on or after that date — the same gate
 * SalePostingService itself enforces, so this can never backfill a
 * business's pre-cutover history even if run against old data.
 */
class PostPendingSales extends Command
{
    protected $signature = 'accounting:post-pending-sales';

    protected $description = 'Retry accounting posting for sales left unposted after their first sync';

    public function handle(SalePostingService $posting): int
    {
        $businesses = Business::whereNotNull('accounting_go_live_date')->get();

        $attempted = 0;

        foreach ($businesses as $business) {
            $postedIds = JournalHeader::where('business_id', $business->id)
                ->where('source_type', 'sale')
                ->pluck('source_id');

            $pending = Transaction::where('business_id', $business->id)
                ->whereIn('status', ['completed', 'credit_sale', 'refunded', 'partial_refund', 'voided'])
                ->whereDate('created_at', '>=', $business->accounting_go_live_date)
                ->whereNotIn('id', $postedIds)
                ->get();

            foreach ($pending as $transaction) {
                $posting->postIfReady($transaction);
                $attempted++;
            }
        }

        $this->info("Attempted {$attempted} pending sale(s) across {$businesses->count()} live business(es).");

        return self::SUCCESS;
    }
}
