<?php

namespace App\Console\Commands\Accounting;

use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\SupplierPayment;
use App\Services\Accounting\SupplierPaymentService;
use Illuminate\Console\Command;

/**
 * READ-ONLY since 2026-10-15: the server no longer posts journals —
 * the device that performs a transaction posts it. This now only
 * counts what is still unposted; the posting described below no
 * longer happens.
 *
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
    protected $signature = 'accounting:post-pending-supplier-payments';

    protected $description = 'Report (read-only) supplier payments left unposted — the server no longer posts';

    public function handle(): int
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

                $attempted++;
            }
        }

        $this->info("Found {$attempted} unposted supplier payment(s) across {$businesses->count()} live business(es). The server no longer posts — post them on a device.");

        return self::SUCCESS;
    }
}
