<?php

namespace App\Console\Commands\Accounting;

use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\SupplierPayment;
use App\Services\Accounting\SupplierPaymentService;
use Illuminate\Console\Command;

/**
 * Two jobs in one, same shape as `accounting:post-pending-sales`:
 *
 * 1. Ongoing sweep — catches a supplier payment SupplierPaymentService's
 *    inline SyncProcessor hook missed (should be rare).
 * 2. One-off backfill — supplier_payments had no sync path at all until
 *    Flutter gained its own client-side recording, so every already
 *    client-cut-over business needs this to pick up straggler payments.
 *
 * Scheduled every 15 minutes (routes/console.php) once the initial backfill
 * has been run manually.
 */
class PostPendingSupplierPayments extends Command
{
    private const GRACE_PERIOD_HOURS = 1;

    protected $signature = 'accounting:post-pending-supplier-payments';

    protected $description = 'Post accounting entries for supplier payments left unposted (sweep + one-off backfill)';

    public function handle(SupplierPaymentService $posting): int
    {
        $businesses = Business::whereNotNull('accounting_go_live_date')->get();

        $attempted = 0;

        foreach ($businesses as $business) {
            $postedIds = JournalHeader::where('business_id', $business->id)
                ->where('source_type', 'supplier_payment')
                ->pluck('source_id');

            $pending = SupplierPayment::where('business_id', $business->id)
                ->whereDate('payment_date', '>=', $business->accounting_go_live_date)
                ->whereNotIn('id', $postedIds)
                ->get();

            foreach ($pending as $payment) {
                $transDate = ($payment->payment_date ?? now())->toDateString();
                $viaSweep = $business->postsFromClientFor($transDate)
                    && ($payment->payment_date ?? now())->lte(now()->subHours(self::GRACE_PERIOD_HOURS));

                $posting->postIfReady($payment, $viaSweep);
                $attempted++;
            }
        }

        $this->info("Attempted {$attempted} pending supplier payment(s) across {$businesses->count()} live business(es).");

        return self::SUCCESS;
    }
}
