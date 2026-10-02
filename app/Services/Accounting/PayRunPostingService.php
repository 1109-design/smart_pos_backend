<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\ExchangeRate;
use App\Models\Payroll\EmployeeLoan;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\PayRunEmployee;
use App\Models\Payroll\PayRunLine;
use App\Models\Payroll\StatutoryRemittance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server port of the till's pay_run_posting_service.dart, for businesses
 * whose journals the server still posts (not cut over to client posting
 * for the date), or — via the sweep's $viaSweep — any the till never
 * posted. Idempotent on source_type + source_id:
 *
 *  - accrual ('pay_run'): Dr wages (gross) + employer payroll costs /
 *    Cr net wages, PAYE (+ AIDS levy) per currency, NSSA (+ employer + WCIF),
 *    ZIMDEF, NEC, other deductions, staff loans. Reversal runs carry
 *    negative amounts, so the same lines land on the opposite sides.
 *  - payment ('pay_run_payment', source_id "{run employee id}:{currency}"):
 *    Dr net wages / Cr cash, bank or mobile money.
 *  - remittance ('statutory_remittance'): Dr the payable / Cr cash or bank.
 *  - loan issue ('staff_loan'): Dr staff loans / Cr cash or bank.
 *
 * Amounts are USD (ZiG at the run's frozen rate) converted to the
 * business's base currency.
 */
class PayRunPostingService
{
    public const REMITTANCE_ROLES = [
        'paye_usd' => 'paye_payable_usd',
        'paye_zwg' => 'paye_payable_zwg',
        'nssa' => 'nssa_payable',
        'zimdef' => 'zimdef_payable',
        'nec' => 'nec_payable',
        'other_deductions' => 'other_deductions_payable',
    ];

    public function __construct(
        private readonly JournalService $journals,
        private readonly AccountRoleMappingService $mappings,
    ) {}

    private function eligible(string $businessId, string $transDate, bool $viaSweep): ?Business
    {
        $business = Business::find($businessId);
        if (! $business?->accountingIsLive()) {
            return null;
        }
        if ($transDate < $business->accounting_go_live_date->toDateString()) {
            return null;
        }
        if (! $viaSweep && $business->postsFromClientFor($transDate)) {
            return null;
        }

        return $business;
    }

    private function posted(string $sourceType, string $sourceId): bool
    {
        return JournalHeader::where('source_type', $sourceType)->where('source_id', $sourceId)->exists();
    }

    /** USD per one base-currency unit (1 for a USD-based business). */
    private function usdPerBase(Business $business): float
    {
        $base = $business->currency_code ?? 'USD';
        if ($base === 'USD') {
            return 1.0;
        }
        $rate = ExchangeRate::where('business_id', $business->id)->where('from_currency', 'USD')->latest('created_at')->value('rate');

        return $rate > 0 ? (float) $rate : 1.0;
    }

    public function postAccrual(PayRun $run, bool $viaSweep = false): bool
    {
        $transDate = $run->pay_date ? substr((string) $run->pay_date, 0, 10) : now()->toDateString();
        $business = $this->eligible($run->business_id, $transDate, $viaSweep);
        if (! $business || $this->posted('pay_run', $run->id)) {
            return false;
        }

        $employees = PayRunEmployee::where('pay_run_id', $run->id)->get();
        if ($employees->isEmpty()) {
            return false;
        }
        $lines = PayRunLine::where('pay_run_id', $run->id)->get();
        $sum = fn (string $col) => (float) $employees->sum($col);
        $zigRate = (float) $run->zig_rate > 0 ? (float) $run->zig_rate : 1.0;

        $necEe = (float) $lines->where('kind', 'deduction')->where('component_code', 'NEC')->sum('amount_usd');
        $loans = (float) $lines->where('kind', 'deduction')->where('source', 'loan')->sum('amount_usd');

        $amounts = [
            'salary_expense' => $sum('gross_usd'),
            'employer_payroll_expense' => $sum('nssa_employer_usd') + $sum('zimdef_usd') + $sum('wcif_usd') + $sum('nec_employer_usd'),
            'net_wages_payable' => -$sum('net_total_usd'),
            'paye_payable_usd' => -($sum('paye_usd') + $sum('aids_levy_usd')),
            'paye_payable_zwg' => -($sum('paye_zwg') + $sum('aids_levy_zwg')) / $zigRate,
            'nssa_payable' => -($sum('nssa_employee_usd') + $sum('nssa_employer_usd') + $sum('wcif_usd')),
            'zimdef_payable' => -$sum('zimdef_usd'),
            'nec_payable' => -($necEe + $sum('nec_employer_usd')),
            'other_deductions_payable' => -($sum('other_deductions_usd') - $necEe - $loans),
            'staff_loans_receivable' => -$loans,
        ];

        if (abs($sum('gross_usd') - (float) $run->total_gross_usd) > 0.05) {
            Log::warning("Payroll: run {$run->id} total_gross_usd {$run->total_gross_usd} differs from its employees' {$sum('gross_usd')}.");
        }

        $perBase = $this->usdPerBase($business);

        try {
            DB::transaction(function () use ($run, $transDate, $amounts, $perBase) {
                $header = $this->journals->createDraft(
                    $run->business_id,
                    $transDate,
                    'pay_run',
                    $run->id,
                    ($run->kind === 'reversal' ? 'Payroll reversal ' : 'Payroll ').$run->run_number,
                    "pay_run:{$run->id}",
                );
                $net = 0.0;
                foreach ($amounts as $role => $usd) {
                    $base = round($usd / $perBase, 2);
                    if (abs($base) < 0.005) {
                        continue;
                    }
                    $net += $base;
                    $this->signedLine($header, $this->mappings->resolve($run->business_id, $role), $base);
                }
                if (abs($net) >= 0.005 && abs($net) < 0.10) {
                    $rounding = GlAccount::where('business_id', $run->business_id)->where('code', '6060')->first();
                    if ($rounding) {
                        $this->signedLine($header, $rounding, -$net);
                    }
                }
                $this->journals->post($header);
            });
        } catch (Throwable $e) {
            Log::warning("Accounting: failed to post pay run {$run->id}: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    public function postPayment(PayRunEmployee $employee, string $currency, bool $viaSweep = false): bool
    {
        $isUsd = $currency === 'USD';
        $paidAt = $isUsd ? $employee->paid_usd_at : $employee->paid_zwg_at;
        if ($paidAt === null) {
            return false;
        }
        $sourceId = "{$employee->id}:{$currency}";
        $run = PayRun::find($employee->pay_run_id);
        if (! $run || $this->posted('pay_run_payment', $sourceId)) {
            return false;
        }
        $zigRate = (float) $run->zig_rate > 0 ? (float) $run->zig_rate : 1.0;
        $usd = $isUsd ? (float) $employee->net_pay_usd : (float) $employee->net_pay_zwg / $zigRate;

        // A corrected run pays only what its month's reversed runs didn't.
        $reversedIds = PayRun::where('business_id', $run->business_id)
            ->where('period_year', $run->period_year)->where('period_month', $run->period_month)
            ->where('kind', 'regular')->where('status', 'reversed')->pluck('id');
        if ($run->kind === 'regular' && $reversedIds->isNotEmpty()) {
            $already = PayRunEmployee::whereIn('pay_run_id', $reversedIds)
                ->where('employee_id', $employee->employee_id)
                ->whereNotNull($isUsd ? 'paid_usd_at' : 'paid_zwg_at')
                ->sum($isUsd ? 'net_pay_usd' : 'net_pay_zwg');
            $usd -= $isUsd ? (float) $already : (float) $already / $zigRate;
        }

        return $this->postTwoLine(
            $run->business_id,
            substr((string) $paidAt, 0, 10),
            'pay_run_payment',
            $sourceId,
            "Net pay {$employee->employee_name} ({$run->run_number})",
            'net_wages_payable',
            $usd,
            $isUsd ? (string) $employee->paid_usd_method : (string) $employee->paid_zwg_method,
            $isUsd ? $employee->paid_usd_bank_account_id : $employee->paid_zwg_bank_account_id,
            $viaSweep,
        );
    }

    public function postRemittance(StatutoryRemittance $r, bool $viaSweep = false): bool
    {
        if ($this->posted('statutory_remittance', $r->id)) {
            return false;
        }

        return $this->postTwoLine(
            $r->business_id,
            substr((string) $r->paid_at, 0, 10),
            'statutory_remittance',
            $r->id,
            'Remittance '.strtoupper($r->kind)." {$r->period_month}/{$r->period_year}",
            self::REMITTANCE_ROLES[$r->kind] ?? 'other_deductions_payable',
            (float) $r->amount_usd,
            (string) $r->payment_method,
            $r->bank_account_id,
            $viaSweep,
        );
    }

    public function postLoanIssue(EmployeeLoan $loan, bool $viaSweep = false): bool
    {
        if ($this->posted('staff_loan', $loan->id)) {
            return false;
        }

        return $this->postTwoLine(
            $loan->business_id,
            substr((string) $loan->issued_at, 0, 10),
            'staff_loan',
            $loan->id,
            $loan->kind === 'advance' ? 'Salary advance' : 'Staff loan',
            'staff_loans_receivable',
            (float) $loan->principal_usd,
            (string) $loan->payment_method,
            $loan->bank_account_id,
            $viaSweep,
        );
    }

    private function postTwoLine(
        string $businessId,
        string $transDate,
        string $sourceType,
        string $sourceId,
        string $description,
        string $debitRole,
        float $usd,
        string $method,
        ?string $bankAccountId,
        bool $viaSweep,
    ): bool {
        $business = $this->eligible($businessId, $transDate, $viaSweep);
        if (! $business) {
            return false;
        }
        $amount = round($usd / $this->usdPerBase($business), 2);
        if (abs($amount) < 0.005) {
            return false;
        }

        try {
            DB::transaction(function () use ($businessId, $transDate, $sourceType, $sourceId, $description, $debitRole, $amount, $method, $bankAccountId) {
                $header = $this->journals->createDraft($businessId, $transDate, $sourceType, $sourceId, $description, "{$sourceType}:{$sourceId}");
                $this->signedLine($header, $this->mappings->resolve($businessId, $debitRole), $amount);
                $this->signedLine($header, $this->fundingAccount($businessId, $method, $bankAccountId), -$amount);
                $this->journals->post($header);
            });
        } catch (Throwable $e) {
            Log::warning("Accounting: failed to post {$sourceType} {$sourceId}: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    private function signedLine(JournalHeader $header, GlAccount $account, float $amount): void
    {
        $this->journals->addLine($header, [
            'gl_account_id' => $account->id,
            'debit' => $amount > 0 ? $amount : 0,
            'credit' => $amount < 0 ? -$amount : 0,
        ]);
    }

    /** Same resolution as SalaryPostingService. */
    private function fundingAccount(string $businessId, string $method, ?string $bankAccountId): GlAccount
    {
        $role = match (true) {
            $method === 'mobile_money' => 'default_mobile_money',
            in_array($method, ['bank_transfer', 'cheque', 'eft', 'card'], true) => 'default_bank',
            default => 'default_cash',
        };
        $account = $this->mappings->resolve($businessId, $role);
        if ($role !== 'default_bank' || ! $bankAccountId) {
            return $account;
        }
        $bank = BankAccount::find($bankAccountId);
        $gl = $bank ? GlAccount::find($bank->gl_account_id) : null;

        return $gl ?? $account;
    }
}
