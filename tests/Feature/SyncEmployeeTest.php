<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private SyncProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = app(SyncProcessor::class);
    }

    // ── Employee upsert ───────────────────────────────────────────────────────

    public function test_creates_employee_on_upsert(): void
    {
        $id = '11111111-1111-1111-1111-111111111111';

        $this->processor->process('employees', $id, 'upsert', [
            'business_id' => 'biz-001',
            'name' => 'Jane Doe',
            'job_title' => 'Cashier',
            'department' => 'Sales',
            'pay_type' => 'monthly',
            'salary_amount' => 500.00,
            'currency_code' => 'USD',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('employees', [
            'id' => $id,
            'name' => 'Jane Doe',
            'job_title' => 'Cashier',
            'status' => 'active',
            'pay_type' => 'monthly',
        ]);
    }

    public function test_updates_employee_on_upsert(): void
    {
        $id = '22222222-2222-2222-2222-222222222222';

        Employee::create([
            'id' => $id,
            'business_id' => 'biz-001',
            'name' => 'Old Name',
            'salary_amount' => 300,
            'currency_code' => 'USD',
        ]);

        $this->processor->process('employees', $id, 'upsert', [
            'business_id' => 'biz-001',
            'name' => 'Jane Updated',
            'salary_amount' => 600.00,
            'currency_code' => 'USD',
            'status' => 'on_leave',
        ]);

        $this->assertDatabaseHas('employees', [
            'id' => $id,
            'name' => 'Jane Updated',
            'status' => 'on_leave',
        ]);
    }

    public function test_deletes_employee(): void
    {
        $id = '33333333-3333-3333-3333-333333333333';

        Employee::create([
            'id' => $id,
            'business_id' => 'biz-001',
            'name' => 'To Delete',
            'salary_amount' => 0,
            'currency_code' => 'USD',
        ]);

        $this->processor->process('employees', $id, 'delete', []);

        // Employees are soft-deactivated, not hard-deleted, so payroll/audit
        // history referencing them (e.g. salary_payments) stays intact.
        $this->assertDatabaseHas('employees', ['id' => $id, 'status' => 'inactive']);
    }

    // ── Salary payment upsert ─────────────────────────────────────────────────

    public function test_creates_salary_payment_on_upsert(): void
    {
        $empId = '44444444-4444-4444-4444-444444444444';
        $payId = '55555555-5555-5555-5555-555555555555';

        Employee::create([
            'id' => $empId,
            'business_id' => 'biz-001',
            'name' => 'John Smith',
            'salary_amount' => 500,
            'currency_code' => 'USD',
        ]);

        $this->processor->process('salary_payments', $payId, 'upsert', [
            'business_id' => 'biz-001',
            'employee_id' => $empId,
            'period' => '2026-05',
            'amount' => 500.00,
            'currency_code' => 'USD',
            'base_equivalent' => 500.00,
            'exchange_rate' => 1.0,
            'payment_method' => 'cash',
            'paid_by_user_id' => 'user-001',
            'paid_at' => '2026-05-31T10:00:00.000000Z',
        ]);

        $this->assertDatabaseHas('salary_payments', [
            'id' => $payId,
            'employee_id' => $empId,
            'period' => '2026-05',
            'payment_method' => 'cash',
        ]);
    }

    public function test_deletes_salary_payment(): void
    {
        $empId = '66666666-6666-6666-6666-666666666666';
        $payId = '77777777-7777-7777-7777-777777777777';

        Employee::create([
            'id' => $empId,
            'business_id' => 'biz-001',
            'name' => 'Del Employee',
            'salary_amount' => 0,
            'currency_code' => 'USD',
        ]);

        SalaryPayment::create([
            'id' => $payId,
            'business_id' => 'biz-001',
            'employee_id' => $empId,
            'period' => '2026-04',
            'amount' => 400,
            'currency_code' => 'USD',
            'base_equivalent' => 400,
            'paid_by_user_id' => 'user-001',
            'paid_at' => now(),
        ]);

        $this->processor->process('salary_payments', $payId, 'delete', []);

        $this->assertDatabaseMissing('salary_payments', ['id' => $payId]);
    }

    public function test_employee_with_minimal_payload(): void
    {
        $id = '88888888-8888-8888-8888-888888888888';

        $this->processor->process('employees', $id, 'upsert', [
            'business_id' => 'biz-002',
            'name' => 'Minimal Employee',
        ]);

        $employee = Employee::find($id);
        $this->assertNotNull($employee);
        $this->assertNull($employee->job_title);
        $this->assertNull($employee->department);
        $this->assertEquals('monthly', $employee->pay_type);
        $this->assertEquals('active', $employee->status);
    }
}
