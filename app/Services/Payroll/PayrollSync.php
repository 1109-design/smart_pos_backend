<?php

namespace App\Services\Payroll;

use App\Models\Payroll\EmployeeLeaveEntry;
use App\Models\Payroll\EmployeeLoan;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\EmployeePaySplit;
use App\Models\Payroll\EmployeeRecurringComponent;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayrollModel;
use App\Models\Payroll\PayrollPeriodLock;
use App\Models\Payroll\PayrollSetting;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\PayRunEmployee;
use App\Models\Payroll\PayRunLine;
use App\Models\Payroll\StatutoryRemittance;
use App\Models\Payroll\StatutorySetting;
use App\Models\Payroll\TaxTable;
use App\Models\Payroll\TaxTableBand;
use App\Models\User;
use App\Services\Accounting\PayRunPostingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Applies payroll rows pushed from the tills. The tills calculate
 * everything (payroll_engine.dart); this stores what they send, filtered
 * to real columns, and enforces what the server must:
 *
 *  - An approved, paid or reversed run is locked: its status may only move
 *    approved → paid → reversed, its employees may only gain payment
 *    details, its lines never change, and none of it can be deleted.
 *  - A regular run can only become approved while it holds its month's
 *    lock (payroll_period_locks, claimed online at approval), approved by
 *    someone holding approvePayroll.
 *  - Leave entries and statutory remittances are append-only.
 *
 * It then posts journals for businesses the server still posts for (see
 * PayRunPostingService).
 */
class PayrollSync
{
    /** @var array<string, class-string<PayrollModel>> */
    public const MODELS = [
        'payroll_settings' => PayrollSetting::class,
        'pay_components' => PayComponent::class,
        'employee_pay_profiles' => EmployeePayProfile::class,
        'employee_pay_splits' => EmployeePaySplit::class,
        'employee_recurring_components' => EmployeeRecurringComponent::class,
        'employee_loans' => EmployeeLoan::class,
        'tax_tables' => TaxTable::class,
        'tax_table_bands' => TaxTableBand::class,
        'statutory_settings' => StatutorySetting::class,
        'pay_runs' => PayRun::class,
        'pay_run_employees' => PayRunEmployee::class,
        'pay_run_lines' => PayRunLine::class,
        'statutory_remittances' => StatutoryRemittance::class,
        'employee_leave_entries' => EmployeeLeaveEntry::class,
    ];

    public const APPEND_ONLY = ['statutory_remittances', 'employee_leave_entries'];

    private const LOCKED_STATUSES = ['approved', 'paid', 'reversed'];

    private const RUN_TRANSITIONS = [
        'approved' => ['approved', 'paid', 'reversed'],
        'paid' => ['paid', 'reversed'],
        'reversed' => ['reversed'],
    ];

    private const PAYMENT_COLUMNS = [
        'paid_usd_at', 'paid_usd_method', 'paid_usd_bank_account_id',
        'paid_zwg_at', 'paid_zwg_method', 'paid_zwg_bank_account_id',
        'paid_by_user_id',
    ];

    private const DATE_COLUMNS = [
        'effective_from', 'effective_to', 'issued_at', 'period_start',
        'period_end', 'pay_date', 'calculated_at', 'approved_at',
        'paid_usd_at', 'paid_zwg_at', 'paid_at', 'entry_date',
    ];

    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(
        private readonly PayRunPostingService $posting,
        private readonly TillPermissions $permissions,
    ) {}

    public static function handles(string $table): bool
    {
        return isset(self::MODELS[$table]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsert(string $table, string $uuid, array $payload, bool $trusted = true): void
    {
        $model = self::MODELS[$table];
        $data = $this->clean($table, $payload);
        $existing = $model::find($uuid);

        if (in_array($table, self::APPEND_ONLY, true) && $existing) {
            return; // first write wins
        }

        if (! $trusted) {
            match ($table) {
                'pay_runs' => $data = $this->guardRun($existing, $uuid, $data),
                'pay_run_employees' => $data = $this->guardRunEmployee($existing, $data),
                'pay_run_lines' => $data = $this->guardRunLine($existing, $data),
                default => null,
            };
            if ($data === null) {
                return; // unchanged re-push of a locked row
            }
        }

        $wasPaidUsd = $existing?->paid_usd_at;
        $wasPaidZwg = $existing?->paid_zwg_at;
        $wasStatus = $existing?->status;

        $row = $model::updateOrCreate(['id' => $uuid], $data);

        $this->postAfter($table, $row, $existing === null, $wasStatus, $wasPaidUsd, $wasPaidZwg);
    }

    public function delete(string $table, string $uuid, bool $trusted = true): void
    {
        if (in_array($table, self::APPEND_ONLY, true)) {
            return;
        }
        $model = self::MODELS[$table];
        $row = $model::find($uuid);
        if (! $row) {
            return;
        }
        if (! $trusted) {
            $runId = match ($table) {
                'pay_runs' => $row->id,
                'pay_run_employees', 'pay_run_lines' => $row->pay_run_id,
                default => null,
            };
            if ($runId !== null && $this->isLocked(PayRun::find($runId))) {
                throw new \RuntimeException("{$table}: an approved pay run can't be changed — reverse it instead.");
            }
        }
        $row->delete();
    }

    // ── Guards ─────────────────────────────────────────────────────────────

    private function isLocked(?PayRun $run): bool
    {
        return $run !== null && in_array($run->status, self::LOCKED_STATUSES, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function guardRun(?PayRun $existing, string $uuid, array $data): ?array
    {
        $incoming = $data['status'] ?? $existing?->status ?? 'draft';

        if ($this->isLocked($existing)) {
            if (! in_array($incoming, self::RUN_TRANSITIONS[$existing->status], true)) {
                throw new \RuntimeException("pay_runs: run {$existing->run_number} is {$existing->status} and can't go back to {$incoming}.");
            }
            if ($incoming === $existing->status) {
                return null;
            }

            // Only the status moves on a locked run.
            return ['status' => $incoming];
        }

        if (in_array($incoming, self::LOCKED_STATUSES, true)) {
            $this->assertApprovable($uuid, $data);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertApprovable(string $uuid, array $data): void
    {
        $businessId = (string) ($data['business_id'] ?? '');
        $approver = User::find($data['approved_by_user_id'] ?? null);
        if (! $this->permissions->userHas($approver, $businessId, 'approvePayroll')) {
            throw new \RuntimeException('pay_runs: the approver is not allowed to approve payroll.');
        }

        if (($data['kind'] ?? 'regular') === 'reversal') {
            $original = PayRun::find($data['reverses_run_id'] ?? null);
            if (! $original || (string) $original->business_id !== $businessId
                || ! $this->isLocked($original)) {
                throw new \RuntimeException('pay_runs: a reversal must reverse an approved run of this business.');
            }

            return;
        }

        $lock = PayrollPeriodLock::where('business_id', $businessId)
            ->where('period_year', $data['period_year'] ?? null)
            ->where('period_month', $data['period_month'] ?? null)
            ->first();
        if (! $lock || $lock->pay_run_id !== $uuid) {
            throw new \RuntimeException('pay_runs: this month was not locked for this run — approve it again while online.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function guardRunEmployee(?PayRunEmployee $existing, array $data): ?array
    {
        $run = PayRun::find($existing?->pay_run_id ?? $data['pay_run_id'] ?? null);
        if (! $this->isLocked($run)) {
            return $data;
        }
        if ($existing === null) {
            if ($run->kind === 'reversal') {
                return $data;
            }
            throw new \RuntimeException('pay_run_employees: an approved pay run can\'t gain employees.');
        }

        // Only payment details may change on a locked run.
        $payment = array_intersect_key($data, array_flip(self::PAYMENT_COLUMNS));
        foreach ($payment as $col => $value) {
            if ($existing->{$col} !== null && str_ends_with($col, '_at')) {
                unset($payment[$col]); // a payment, once recorded, stands
            }
        }

        return $payment === [] ? null : $payment;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function guardRunLine(?PayRunLine $existing, array $data): ?array
    {
        $run = PayRun::find($existing?->pay_run_id ?? $data['pay_run_id'] ?? null);
        if (! $this->isLocked($run)) {
            return $data;
        }
        if ($existing === null && $run->kind === 'reversal') {
            return $data;
        }
        if ($existing !== null && abs((float) $existing->amount - (float) ($data['amount'] ?? $existing->amount)) < 0.005) {
            return null;
        }
        throw new \RuntimeException('pay_run_lines: an approved pay run\'s lines can\'t change.');
    }

    // ── Posting ────────────────────────────────────────────────────────────

    private function postAfter(string $table, PayrollModel $row, bool $created, ?string $wasStatus, mixed $wasPaidUsd, mixed $wasPaidZwg): void
    {
        switch ($table) {
            case 'pay_runs':
                // A reversal's employees and lines arrive after the run
                // itself; the sweep (accounting:post-pending-payroll) posts
                // it once they have. A regular run's lines came up with its
                // calculation, before approval.
                if ($row->kind === 'regular' && $row->status === 'approved' && $wasStatus !== 'approved') {
                    $this->posting->postAccrual($row);
                }
                break;
            case 'pay_run_employees':
                if ($row->paid_usd_at !== null && $wasPaidUsd === null) {
                    $this->posting->postPayment($row, 'USD');
                }
                if ($row->paid_zwg_at !== null && $wasPaidZwg === null) {
                    $this->posting->postPayment($row, 'ZWG');
                }
                break;
            case 'statutory_remittances':
                if ($created) {
                    $this->posting->postRemittance($row);
                }
                break;
            case 'employee_loans':
                if ($created) {
                    $this->posting->postLoanIssue($row);
                }
                break;
        }
    }

    // ── Payload cleaning ───────────────────────────────────────────────────

    /**
     * Keeps real columns only (not id or timestamps), with dates parsed.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function clean(string $table, array $payload): array
    {
        $columns = $this->columns[$table] ??= Schema::getColumnListing($table);
        $data = [];
        foreach ($payload as $key => $value) {
            if (! in_array($key, $columns, true) || in_array($key, ['id', 'created_at', 'updated_at'], true)) {
                continue;
            }
            if ($value !== null && in_array($key, self::DATE_COLUMNS, true)) {
                $value = is_int($value) ? Carbon::createFromTimestampMs($value) : Carbon::parse((string) $value);
            }
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            $data[$key] = $value;
        }

        return $data;
    }
}
