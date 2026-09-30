<?php

namespace Tests\Feature;

use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Device;
use App\Models\Payroll\EmployeeLeaveEntry;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayrollPeriodLock;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\PayRunEmployee;
use App\Models\Payroll\PayRunLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Payroll pushed from a till: rows stored as sent, approved runs locked,
 * approval gated on the month lock, append-only ledgers, and journals for
 * businesses the server still posts for.
 */
class SyncPayrollTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-payroll-1';

    private User $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Tenant::create(['id' => $this->tenantId, 'business_name' => 'Pay Co', 'owner_email' => 'pay@example.com']);
        Business::create(['id' => $this->tenantId, 'name' => 'Pay Co', 'currency_code' => 'USD']);
        [$this->owner, $this->token] = $this->userWithToken('business_owner');
    }

    /** @return array{0: User, 1: string} */
    private function userWithToken(string $role): array
    {
        $user = User::factory()->create(['business_id' => $this->tenantId, 'email' => Str::random(6).'@example.com']);
        $user->assignRole($role);
        $plain = $user->createToken('sync-test')->plainTextToken;
        Device::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return [$user, $plain];
    }

    private function push(array $records, ?string $token = null)
    {
        return $this->withHeader('Authorization', 'Bearer '.($token ?? $this->token))
            ->postJson('/api/v1/sync/push', ['records' => array_map(fn ($r) => $r + [
                'operation' => 'upsert',
                'updated_at' => now()->toIso8601String(),
            ], $records)]);
    }

    private function lock(string $runId, array $extra = [], ?string $userId = null)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/payroll/period-locks', $extra + [
                'pay_run_id' => $runId,
                'period_year' => 2026,
                'period_month' => 9,
                'user_id' => $userId ?? $this->owner->id,
            ]);
    }

    private function runPayload(string $status, array $extra = []): array
    {
        return $extra + [
            'business_id' => $this->tenantId,
            'run_number' => 'PAY-202609-1',
            'period_year' => 2026,
            'period_month' => 9,
            'period_start' => '2026-09-01T00:00:00.000Z',
            'period_end' => '2026-09-30T21:59:59.000Z',
            'pay_date' => '2026-09-30T00:00:00.000Z',
            'status' => $status,
            'kind' => 'regular',
            'zig_rate' => 26.5,
            'zig_rate_overridden' => false,
            'total_gross_usd' => 1000,
            'total_net_usd' => 800,
            'headcount' => 1,
            'created_by_user_id' => $this->owner->id,
            'approved_by_user_id' => $status === 'calculated' ? null : $this->owner->id,
            'approved_at' => $status === 'calculated' ? null : '2026-09-30T10:00:00.000Z',
        ];
    }

    /** A calculated run: one employee, gross 1000, PAYE 150, NSSA 45, net 805. */
    private function seedCalculatedRun(): array
    {
        $runId = (string) Str::uuid();
        $empRowId = (string) Str::uuid();
        $this->push([
            ['table' => 'pay_runs', 'uuid' => $runId, 'payload' => $this->runPayload('calculated')],
            ['table' => 'pay_run_employees', 'uuid' => $empRowId, 'payload' => [
                'business_id' => $this->tenantId,
                'pay_run_id' => $runId,
                'employee_id' => (string) Str::uuid(),
                'employee_name' => 'Alice',
                'gross_usd' => 1000,
                'taxable_usd' => 955,
                'paye_usd' => 150,
                'nssa_employee_usd' => 45,
                'net_total_usd' => 805,
                'net_pay_usd' => 805,
                'nssa_employer_usd' => 45,
                'zimdef_usd' => 10,
            ]],
            ['table' => 'pay_run_lines', 'uuid' => (string) Str::uuid(), 'payload' => [
                'business_id' => $this->tenantId,
                'pay_run_id' => $runId,
                'pay_run_employee_id' => $empRowId,
                'component_code' => 'BASIC',
                'name' => 'Basic pay',
                'kind' => 'earning',
                'source' => 'profile',
                'amount' => 1000,
                'amount_usd' => 1000,
            ]],
        ])->assertOk();

        return [$runId, $empRowId];
    }

    public function test_setup_rows_are_stored_with_dates(): void
    {
        $id = (string) Str::uuid();
        $this->push([['table' => 'employee_pay_profiles', 'uuid' => $id, 'payload' => [
            'id' => $id,
            'business_id' => $this->tenantId,
            'employee_id' => (string) Str::uuid(),
            'pay_basis' => 'salaried',
            'basic_usd' => 950.5,
            'effective_from' => '2026-01-01T00:00:00.000Z',
            'is_elderly' => true,
            'created_at' => '2026-09-30T00:00:00.000Z',
            'not_a_column' => 'ignored',
        ]]])->assertJsonCount(1, 'accepted');

        $row = EmployeePayProfile::findOrFail($id);
        $this->assertEqualsWithDelta(950.5, (float) $row->basic_usd, 0.001);
        $this->assertStringStartsWith('2026-01-01', (string) $row->effective_from);
        $this->assertSame(1, (int) $row->is_elderly);
    }

    public function test_approval_needs_the_month_lock(): void
    {
        [$runId] = $this->seedCalculatedRun();

        $this->push([['table' => 'pay_runs', 'uuid' => $runId, 'payload' => $this->runPayload('approved')]])
            ->assertJsonCount(1, 'errors');
        $this->assertSame('calculated', PayRun::findOrFail($runId)->status);

        $this->lock($runId)->assertOk();
        $this->push([['table' => 'pay_runs', 'uuid' => $runId, 'payload' => $this->runPayload('approved')]])
            ->assertJsonCount(1, 'accepted');
        $this->assertSame('approved', PayRun::findOrFail($runId)->status);
    }

    public function test_only_one_run_can_lock_a_month(): void
    {
        $this->lock('run-a')->assertOk();
        $this->lock('run-a')->assertOk(); // same run again is fine
        $this->lock('run-b')->assertStatus(409);

        // Reversing run-a releases the month for a corrected run.
        $this->lock('run-a', ['kind' => 'reversal', 'releases_run_id' => 'run-a'])->assertOk();
        $this->lock('run-b')->assertOk();
        $this->assertSame('run-b', PayrollPeriodLock::firstOrFail()->pay_run_id);
    }

    public function test_lock_requires_approve_payroll(): void
    {
        [$cashier] = $this->userWithToken('cashier');
        $this->lock('run-a', [], $cashier->id)->assertStatus(403);
    }

    public function test_an_approved_run_is_locked(): void
    {
        [$runId, $empRowId] = $this->seedCalculatedRun();
        $this->lock($runId)->assertOk();
        $this->push([['table' => 'pay_runs', 'uuid' => $runId, 'payload' => $this->runPayload('approved')]])->assertOk();

        // Back to draft is refused; amounts can't change.
        $this->push([['table' => 'pay_runs', 'uuid' => $runId, 'payload' => $this->runPayload('draft')]])
            ->assertJsonCount(1, 'errors');
        $this->push([['table' => 'pay_runs', 'uuid' => $runId, 'payload' => $this->runPayload('paid', ['total_gross_usd' => 5])]])
            ->assertOk();
        $run = PayRun::findOrFail($runId);
        $this->assertSame('paid', $run->status);
        $this->assertEqualsWithDelta(1000, (float) $run->total_gross_usd, 0.001);

        // Employees only gain payment details.
        $this->push([['table' => 'pay_run_employees', 'uuid' => $empRowId, 'payload' => [
            'business_id' => $this->tenantId,
            'pay_run_id' => $runId,
            'net_pay_usd' => 9999,
            'paid_usd_at' => '2026-09-30T12:00:00.000Z',
            'paid_usd_method' => 'cash',
        ]]])->assertOk();
        $emp = PayRunEmployee::findOrFail($empRowId);
        $this->assertEqualsWithDelta(805, (float) $emp->net_pay_usd, 0.001);
        $this->assertNotNull($emp->paid_usd_at);

        // Lines and the run itself can't be deleted.
        $line = PayRunLine::where('pay_run_id', $runId)->firstOrFail();
        $this->push([['table' => 'pay_run_lines', 'uuid' => $line->id, 'operation' => 'delete', 'payload' => ['business_id' => $this->tenantId]]])
            ->assertJsonCount(1, 'errors');
        $this->assertNotNull(PayRunLine::find($line->id));
    }

    public function test_leave_entries_are_append_only(): void
    {
        $id = (string) Str::uuid();
        $payload = [
            'business_id' => $this->tenantId,
            'employee_id' => (string) Str::uuid(),
            'kind' => 'accrual',
            'days' => 2.5,
            'entry_date' => '2026-09-30T00:00:00.000Z',
        ];
        $this->push([['table' => 'employee_leave_entries', 'uuid' => $id, 'payload' => $payload]])->assertOk();
        $this->push([['table' => 'employee_leave_entries', 'uuid' => $id, 'payload' => ['days' => 99] + $payload]])->assertOk();
        $this->push([['table' => 'employee_leave_entries', 'uuid' => $id, 'operation' => 'delete', 'payload' => ['business_id' => $this->tenantId]]])->assertOk();

        $this->assertEqualsWithDelta(2.5, (float) EmployeeLeaveEntry::findOrFail($id)->days, 0.001);
    }

    public function test_server_posts_accrual_and_payment_when_it_owns_posting(): void
    {
        DB::table('businesses')->where('id', $this->tenantId)->update(['accounting_go_live_date' => '2026-01-01']);
        app(ChartOfAccountsSeeder::class)->seedForBusiness($this->tenantId);

        [$runId, $empRowId] = $this->seedCalculatedRun();
        $this->lock($runId)->assertOk();
        $this->push([['table' => 'pay_runs', 'uuid' => $runId, 'payload' => $this->runPayload('approved')]])->assertOk();

        $accrual = JournalHeader::where('source_type', 'pay_run')->where('source_id', $runId)->firstOrFail();
        $this->assertSame('posted', $accrual->status);
        $this->assertEqualsWithDelta(
            (float) $accrual->lines()->sum('debit'),
            (float) $accrual->lines()->sum('credit'),
            0.001,
        );

        $this->push([['table' => 'pay_run_employees', 'uuid' => $empRowId, 'payload' => [
            'business_id' => $this->tenantId,
            'pay_run_id' => $runId,
            'paid_usd_at' => '2026-09-30T12:00:00.000Z',
            // Cash and bank can't go negative in an empty ledger.
            'paid_usd_method' => 'mobile_money',
        ]]])->assertOk();
        $this->assertTrue(JournalHeader::where('source_type', 'pay_run_payment')->where('source_id', "{$empRowId}:USD")->exists());
    }

    public function test_reversal_is_accepted_and_posted_by_the_sweep(): void
    {
        DB::table('businesses')->where('id', $this->tenantId)->update(['accounting_go_live_date' => '2026-01-01']);
        app(ChartOfAccountsSeeder::class)->seedForBusiness($this->tenantId);

        [$runId, $empRowId] = $this->seedCalculatedRun();
        $this->lock($runId)->assertOk();
        $this->push([['table' => 'pay_runs', 'uuid' => $runId, 'payload' => $this->runPayload('approved')]])->assertOk();

        $revId = (string) Str::uuid();
        $revEmpId = (string) Str::uuid();
        $this->lock($runId, ['kind' => 'reversal', 'releases_run_id' => $runId])->assertOk();
        $this->push([
            ['table' => 'pay_runs', 'uuid' => $revId, 'payload' => $this->runPayload('approved', [
                'run_number' => 'REV-202609-2',
                'kind' => 'reversal',
                'reverses_run_id' => $runId,
                'total_gross_usd' => -1000,
                'total_net_usd' => -800,
            ])],
            ['table' => 'pay_runs', 'uuid' => $runId, 'payload' => $this->runPayload('reversed')],
            ['table' => 'pay_run_employees', 'uuid' => $revEmpId, 'payload' => [
                'business_id' => $this->tenantId,
                'pay_run_id' => $revId,
                'employee_id' => (string) Str::uuid(),
                'employee_name' => 'Alice',
                'gross_usd' => -1000,
                'paye_usd' => -150,
                'nssa_employee_usd' => -45,
                'net_total_usd' => -805,
                'net_pay_usd' => -805,
                'nssa_employer_usd' => -45,
                'zimdef_usd' => -10,
            ]],
        ])->assertJsonCount(3, 'accepted');
        $this->assertSame('reversed', PayRun::findOrFail($runId)->status);
        $this->assertFalse(JournalHeader::where('source_id', $revId)->exists());

        $this->travel(10)->minutes();
        $this->artisan('accounting:post-pending-payroll')->assertSuccessful();

        $reversal = JournalHeader::where('source_type', 'pay_run')->where('source_id', $revId)->firstOrFail();
        $this->assertSame('posted', $reversal->status);
        // Together the accrual and its reversal net every account to zero.
        $net = DB::table('journal_lines')
            ->join('journal_headers', 'journal_headers.id', '=', 'journal_lines.journal_header_id')
            ->where('journal_headers.source_type', 'pay_run')
            ->selectRaw('journal_lines.gl_account_id, SUM(debit) - SUM(credit) AS net')
            ->groupBy('journal_lines.gl_account_id')->pluck('net');
        foreach ($net as $value) {
            $this->assertEqualsWithDelta(0, (float) $value, 0.001);
        }
    }
}
