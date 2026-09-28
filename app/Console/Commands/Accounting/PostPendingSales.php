<?php

namespace App\Console\Commands\Accounting;

use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Transaction;
use App\Services\Accounting\SalePostingService;
use Illuminate\Console\Command;

/**
 * RECOVERY / FALLBACK ONLY — not the accounting engine.
 *
 * The primary posting path is Flutter itself: the till creates the sale AND
 * its journal locally at checkout (SalePostingService, offline-capable) and
 * syncs both up. This command only catches whatever the inline hooks missed
 * on the first pass — mainly a sale whose items/payments hadn't all synced
 * transaction row first landed (see SalePostingService's doc comment).
 * Scheduled every 15 minutes (routes/console.php), same cadence as ZIMRA's
 * own retry sweep.
 *
 * Only ever looks at businesses with accounting_go_live_date set, and only
 * at their transactions on or after that date — the same gate
 * SalePostingService itself enforces, so this can never backfill a
 * business's pre-cutover history even if run against old data.
 *
 * Once a business is ALSO cut over to client_gl_posting_enabled_at,
 * postIfReady() itself defers to the client and won't post — that's
 * correct for a normal transaction whose own journal just hasn't synced up
 * yet, but leaves an old-app-version till's sales permanently unposted
 * (it never learned to post its own journal at all). GRACE_PERIOD_HOURS
 * gives a genuinely-in-flight client-side sync time to land before this
 * sweep steps in as a straggler safety net, via postIfReady()'s
 * $viaSweep flag which bypasses the "defer to client" check.
 */
class PostPendingSales extends Command
{
    private const GRACE_PERIOD_HOURS = 1;

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
                $transDate = $transaction->created_at->toDateString();
                $viaSweep = $business->postsFromClientFor($transDate)
                    && $transaction->created_at->lte(now()->subHours(self::GRACE_PERIOD_HOURS));

                $posting->postIfReady($transaction, $viaSweep);
                $attempted++;
            }
        }

        $this->info("Attempted {$attempted} pending sale(s) across {$businesses->count()} live business(es).");

        return self::SUCCESS;
    }
}
