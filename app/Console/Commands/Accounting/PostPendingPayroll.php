<?php

namespace App\Console\Commands\Accounting;

use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Payroll\EmployeeLoan;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\PayRunEmployee;
use App\Models\Payroll\StatutoryRemittance;
use App\Services\Accounting\PayRunPostingService;
use Illuminate\Console\Command;

/**
 * Posts payroll journals left unposted: pay run accruals (a reversal run's
 * lines reach the server after the run itself, so it is only posted here),
 * net pay payments, statutory remittances and staff loans. For a business
 * cut over to client posting, only rows older than the grace period are
 * posted — the till should have done it by then. See
 * PostPendingSalaryPayments for the same pattern.
 */
class PostPendingPayroll extends Command
{
    private const GRACE_PERIOD_HOURS = 1;

    /** A reversal is posted once its rows have had time to arrive. */
    private const REVERSAL_SETTLE_MINUTES = 5;

    protected $signature = 'accounting:post-pending-payroll';

    protected $description = 'Post accounting entries for payroll left unposted (pay runs, net pay, remittances, staff loans)';

    public function handle(PayRunPostingService $posting): int
    {
        $attempted = 0;
        foreach (Business::whereNotNull('accounting_go_live_date')->get() as $business) {
            $posted = fn (string $type) => JournalHeader::where('business_id', $business->id)
                ->where('source_type', $type)->pluck('source_id')->all();
            $viaSweep = fn ($at) => $business->postsFromClientFor(substr((string) $at, 0, 10))
                && $at !== null && now()->subHours(self::GRACE_PERIOD_HOURS)->gte($at);

            $runs = PayRun::where('business_id', $business->id)
                ->whereIn('status', ['approved', 'paid', 'reversed'])
                ->whereNotIn('id', $posted('pay_run'))
                ->where('updated_at', '<=', now()->subMinutes(self::REVERSAL_SETTLE_MINUTES))
                ->get();
            foreach ($runs as $run) {
                $posting->postAccrual($run, $viaSweep($run->approved_at ?? $run->pay_date));
                $attempted++;
            }

            $paidDone = $posted('pay_run_payment');
            $emps = PayRunEmployee::where('business_id', $business->id)
                ->where(fn ($q) => $q->whereNotNull('paid_usd_at')->orWhereNotNull('paid_zwg_at'))
                ->get();
            foreach ($emps as $e) {
                foreach (['USD' => $e->paid_usd_at, 'ZWG' => $e->paid_zwg_at] as $currency => $at) {
                    if ($at === null || in_array("{$e->id}:{$currency}", $paidDone, true)) {
                        continue;
                    }
                    $posting->postPayment($e, $currency, $viaSweep($at));
                    $attempted++;
                }
            }

            foreach (StatutoryRemittance::where('business_id', $business->id)
                ->whereNotIn('id', $posted('statutory_remittance'))->get() as $r) {
                $posting->postRemittance($r, $viaSweep($r->paid_at));
                $attempted++;
            }

            foreach (EmployeeLoan::where('business_id', $business->id)
                ->whereNotIn('id', $posted('staff_loan'))->get() as $loan) {
                $posting->postLoanIssue($loan, $viaSweep($loan->issued_at));
                $attempted++;
            }
        }

        $this->info("Attempted {$attempted} pending payroll posting(s).");

        return self::SUCCESS;
    }
}
