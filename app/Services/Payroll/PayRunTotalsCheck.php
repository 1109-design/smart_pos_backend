<?php

namespace App\Services\Payroll;

use App\Models\Payroll\PayRun;
use App\Models\Payroll\PayRunEmployee;
use App\Models\Payroll\PayRunLine;
use Illuminate\Support\Facades\Log;

/**
 * Re-adds a locked pay run from the rows the till synced and flags anything
 * that doesn't add up. It never recalculates tax — the till's engine owns
 * that — it only checks that the run's totals, each employee's results and
 * each payslip's lines agree with one another:
 *
 *  - headcount and each run total = the sum over its employees;
 *  - per employee: earning lines = gross, deduction lines = gross − net,
 *    employer contribution lines = employer cost, and USD net + ZiG net at
 *    the run's rate = net.
 *
 * The till holds the same identities (pay_run_service_test.dart, "run
 * totals and every payslip add up from their lines"). The result is stored
 * on the run (totals_checked_at, totals_mismatch) and logged as a warning;
 * the sync diagnostics endpoint lists flagged runs.
 */
class PayRunTotalsCheck
{
    /** Cents of rounding allowed per summed row. */
    private const TOLERANCE_PER_ROW = 0.01;

    /** @return list<string> what didn't add up; empty when all did */
    public function check(PayRun $run): array
    {
        $employees = PayRunEmployee::where('pay_run_id', $run->id)->get();
        $lines = PayRunLine::where('pay_run_id', $run->id)->get()->groupBy('pay_run_employee_id');
        $problems = [];

        if ((int) $run->headcount !== $employees->count()) {
            $problems[] = "Headcount {$run->headcount} but {$employees->count()} employee row(s) synced.";
        }

        $runTotals = [
            'total_gross_usd' => ['Gross USD', fn ($e) => $e->gross_usd],
            'total_net_usd' => ['Net USD', fn ($e) => $e->net_pay_usd],
            'total_net_zwg' => ['Net ZiG', fn ($e) => $e->net_pay_zwg],
            'total_paye_usd' => ['PAYE USD', fn ($e) => $e->paye_usd],
            'total_paye_zwg' => ['PAYE ZiG', fn ($e) => $e->paye_zwg],
            'total_employer_cost_usd' => ['Employer cost USD', fn ($e) => $this->employerCost($e)],
        ];
        $allowed = self::TOLERANCE_PER_ROW * max(1, $employees->count());
        foreach ($runTotals as $column => [$label, $value]) {
            $sum = (float) $employees->sum(fn ($e) => (float) $value($e));
            $this->compare($problems, "Run {$label}", (float) $run->{$column}, $sum, $allowed);
        }

        $rate = (float) $run->zig_rate;
        foreach ($employees as $e) {
            $own = $lines->get($e->id, collect());
            $name = $e->employee_name ?: $e->employee_id;
            if ($own->isEmpty()) {
                if (abs((float) $e->gross_usd) > 0.005) {
                    $problems[] = "{$name}: no payslip lines synced.";
                }

                continue;
            }
            $sum = fn (string $kind) => (float) $own->where('kind', $kind)->sum(fn ($l) => (float) $l->amount_usd);
            $tolerance = self::TOLERANCE_PER_ROW * max(1, $own->count());
            $gross = (float) $e->gross_usd;
            $this->compare($problems, "{$name}: earnings vs gross", $gross, $sum('earning'), $tolerance);
            $this->compare($problems, "{$name}: deductions vs gross − net", $gross - (float) $e->net_total_usd, $sum('deduction'), $tolerance);
            $this->compare($problems, "{$name}: employer lines vs employer cost", $this->employerCost($e), $sum('employer_contribution'), $tolerance);
            if ($rate > 0 && (float) $e->net_total_usd > 0) {
                $split = (float) $e->net_pay_usd + (float) $e->net_pay_zwg / $rate;
                $this->compare($problems, "{$name}: USD + ZiG net vs net", (float) $e->net_total_usd, $split, 0.02);
            }
        }

        return $problems;
    }

    /**
     * Checks [$run] and stores the result on it.
     *
     * @return list<string>
     */
    public function checkAndRecord(PayRun $run): array
    {
        $problems = $this->check($run);
        // Only these two columns, never the till's data — and through the
        // base query so updated_at (the sweep's settle clock) stays put.
        PayRun::whereKey($run->id)->toBase()->update([
            'totals_checked_at' => now(),
            'totals_mismatch' => $problems === [] ? null : json_encode($problems),
        ]);
        if ($problems !== []) {
            Log::warning("Pay run {$run->run_number} ({$run->id}) doesn't add up", [
                'business_id' => $run->business_id,
                'problems' => $problems,
            ]);
        }

        return $problems;
    }

    private function employerCost(PayRunEmployee $e): float
    {
        return (float) $e->nssa_employer_usd + (float) $e->zimdef_usd
            + (float) $e->wcif_usd + (float) $e->nec_employer_usd;
    }

    /** @param  list<string>  $problems */
    private function compare(array &$problems, string $what, float $expected, float $actual, float $tolerance): void
    {
        if (abs($expected - $actual) > $tolerance + 0.000001) {
            $problems[] = sprintf('%s: %.2f recorded, %.2f from the rows (off by %.2f).', $what, $expected, $actual, $actual - $expected);
        }
    }
}
