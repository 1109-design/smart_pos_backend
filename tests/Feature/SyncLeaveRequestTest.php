<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Business;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Payroll\EmployeeLeaveEntry;
use App\Models\Payroll\EmployeeLeaveRequest;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\Payroll\LeaveRequestDecision;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Leave requests from the tills: created pending, decided once by someone
 * allowed to approve leave who isn't the requester or the employee, and an
 * approved annual request posts exactly one ledger deduction whose id the
 * tills derive too.
 */
class SyncLeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-leave-1';

    private User $owner;

    private string $token;

    private User $manager;

    private User $staff;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Tenant::create(['id' => $this->tenantId, 'business_name' => 'Leave Co', 'owner_email' => 'leave@example.com']);
        Business::create(['id' => $this->tenantId, 'name' => 'Leave Co', 'currency_code' => 'USD']);
        [$this->owner, $this->token] = $this->userWithToken('business_owner');
        [$this->manager] = $this->userWithToken('manager');
        [$this->staff] = $this->userWithToken('cashier');
        $this->employee = Employee::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->tenantId,
            'user_id' => $this->staff->id,
            'name' => 'Tendai',
        ]);
    }

    /** @return array{0: User, 1: string} */
    private function userWithToken(string $role): array
    {
        $user = User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->tenantId,
            'email' => Str::random(6).'@example.com',
            'is_active' => true,
        ]);
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

    private function push(array $records)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/sync/push', ['records' => array_map(fn ($r) => $r + [
                'operation' => 'upsert',
                'updated_at' => now()->toIso8601String(),
            ], $records)]);
    }

    /** @return array<string, mixed> */
    private function leavePayload(array $extra = []): array
    {
        return $extra + [
            'business_id' => $this->tenantId,
            'employee_id' => $this->employee->id,
            'employee_name' => 'Tendai',
            'leave_type' => 'annual',
            'start_date' => '2026-11-16T22:00:00.000Z',
            'end_date' => '2026-11-18T22:00:00.000Z',
            'half_day' => false,
            'days' => 3,
            'reason' => 'Family visit',
            'status' => 'pending',
            'requested_by_user_id' => $this->staff->id,
        ];
    }

    private function pushPending(): string
    {
        $id = (string) Str::uuid();
        $this->push([['table' => 'employee_leave_requests', 'uuid' => $id, 'payload' => $this->leavePayload()]])
            ->assertJsonCount(1, 'accepted');

        return $id;
    }

    private function raiseApproval(string $leaveId): ApprovalRequest
    {
        return app(ApprovalService::class)->request(
            $this->tenantId,
            LeaveRequestDecision::SUBJECT_TYPE,
            $leaveId,
            LeaveRequestDecision::ACTION,
            $this->staff->id,
            ['employee_name' => 'Tendai', 'days' => 3],
        );
    }

    public function test_a_pending_request_is_stored(): void
    {
        $id = $this->pushPending();

        $row = EmployeeLeaveRequest::findOrFail($id);
        $this->assertSame('pending', $row->status);
        $this->assertSame(3.0, $row->days);
        $this->assertSame('annual', $row->leave_type);
    }

    public function test_a_new_request_must_be_pending(): void
    {
        $this->push([[
            'table' => 'employee_leave_requests',
            'uuid' => (string) Str::uuid(),
            'payload' => $this->leavePayload(['status' => 'approved', 'decided_by_user_id' => $this->manager->id]),
        ]])->assertJsonCount(1, 'errors');

        $this->assertSame(0, EmployeeLeaveRequest::count());
    }

    public function test_a_manager_approves_a_pushed_request(): void
    {
        $id = $this->pushPending();

        $this->push([['table' => 'employee_leave_requests', 'uuid' => $id, 'payload' => $this->leavePayload([
            'status' => 'approved',
            'decided_by_user_id' => $this->manager->id,
            'decided_at' => '2026-11-12T10:00:00.000Z',
        ])]])->assertJsonCount(1, 'accepted');

        $this->assertSame('approved', EmployeeLeaveRequest::findOrFail($id)->status);
    }

    public function test_a_cashier_cannot_approve_leave(): void
    {
        $id = $this->pushPending();
        [$otherCashier] = $this->userWithToken('cashier');

        $this->push([['table' => 'employee_leave_requests', 'uuid' => $id, 'payload' => $this->leavePayload([
            'status' => 'approved',
            'decided_by_user_id' => $otherCashier->id,
        ])]])->assertJsonCount(1, 'errors');

        $this->assertSame('pending', EmployeeLeaveRequest::findOrFail($id)->status);
    }

    public function test_the_employee_cannot_decide_their_own_leave_even_when_hr_filed_it(): void
    {
        [$staffManager] = $this->userWithToken('manager');
        $this->employee->update(['user_id' => $staffManager->id]);
        $id = (string) Str::uuid();
        $this->push([['table' => 'employee_leave_requests', 'uuid' => $id, 'payload' => $this->leavePayload([
            'requested_by_user_id' => $this->manager->id,
        ])]])->assertJsonCount(1, 'accepted');

        $this->push([['table' => 'employee_leave_requests', 'uuid' => $id, 'payload' => $this->leavePayload([
            'requested_by_user_id' => $this->manager->id,
            'status' => 'approved',
            'decided_by_user_id' => $staffManager->id,
        ])]])->assertJsonCount(1, 'errors');

        $this->assertSame('pending', EmployeeLeaveRequest::findOrFail($id)->status);
    }

    public function test_a_decided_request_never_changes_status_and_the_device_is_told(): void
    {
        $id = $this->pushPending();
        $this->push([['table' => 'employee_leave_requests', 'uuid' => $id, 'payload' => $this->leavePayload([
            'status' => 'cancelled',
            'cancelled_at' => '2026-11-12T09:00:00.000Z',
        ])]])->assertJsonCount(1, 'accepted');

        $this->push([['table' => 'employee_leave_requests', 'uuid' => $id, 'payload' => $this->leavePayload([
            'status' => 'approved',
            'decided_by_user_id' => $this->manager->id,
        ])]])->assertJsonCount(1, 'accepted');

        $this->assertSame('cancelled', EmployeeLeaveRequest::findOrFail($id)->status);
        $this->assertTrue(SyncRecord::where('table_name', 'employee_leave_requests')
            ->where('record_uuid', $id)
            ->where('payload->status', 'cancelled')
            ->exists());
    }

    public function test_the_request_details_are_fixed_once_raised(): void
    {
        $id = $this->pushPending();

        $this->push([['table' => 'employee_leave_requests', 'uuid' => $id, 'payload' => $this->leavePayload([
            'days' => 10,
        ])]])->assertJsonCount(1, 'accepted');

        $this->assertSame(3.0, EmployeeLeaveRequest::findOrFail($id)->days);
    }

    public function test_back_office_approval_posts_one_deduction_with_the_shared_id(): void
    {
        $id = $this->pushPending();
        $approval = $this->raiseApproval($id);

        app(ApprovalService::class)->resolve($approval->id, $this->manager->id, 'approved');

        $leave = EmployeeLeaveRequest::findOrFail($id);
        $this->assertSame('approved', $leave->status);
        $this->assertSame($this->manager->id, $leave->decided_by_user_id);
        $ledgerId = LeaveRequestDecision::ledgerEntryId($id);
        $this->assertSame($ledgerId, $leave->ledger_entry_id);
        $entry = EmployeeLeaveEntry::findOrFail($ledgerId);
        $this->assertSame('taken', $entry->kind);
        $this->assertEqualsWithDelta(-3.0, (float) $entry->days, 0.001);
        $this->assertSame($id, $entry->leave_request_id);

        // Replayed: nothing more is posted.
        app(LeaveRequestDecision::class)->apply($approval->fresh(), 'approved', $this->manager->id);
        $this->assertSame(1, EmployeeLeaveEntry::where('leave_request_id', $id)->count());
    }

    public function test_a_deduction_pushed_by_the_till_first_is_not_posted_twice(): void
    {
        $id = $this->pushPending();
        $ledgerId = LeaveRequestDecision::ledgerEntryId($id);
        $this->push([['table' => 'employee_leave_entries', 'uuid' => $ledgerId, 'payload' => [
            'business_id' => $this->tenantId,
            'employee_id' => $this->employee->id,
            'kind' => 'taken',
            'days' => -3,
            'entry_date' => '2026-11-16T22:00:00.000Z',
            'leave_request_id' => $id,
        ]]])->assertJsonCount(1, 'accepted');

        $approval = $this->raiseApproval($id);
        app(ApprovalService::class)->resolve($approval->id, $this->manager->id, 'approved');

        $this->assertSame(1, EmployeeLeaveEntry::where('leave_request_id', $id)->count());
        $this->assertSame('approved', EmployeeLeaveRequest::findOrFail($id)->status);
    }

    public function test_the_requester_cannot_approve_from_back_office(): void
    {
        $id = $this->pushPending();
        $this->employee->update(['user_id' => null]);
        $approval = app(ApprovalService::class)->request(
            $this->tenantId,
            LeaveRequestDecision::SUBJECT_TYPE,
            $id,
            LeaveRequestDecision::ACTION,
            $this->manager->id,
        );
        EmployeeLeaveRequest::whereKey($id)->update(['requested_by_user_id' => $this->manager->id]);

        $this->expectException(\RuntimeException::class);
        try {
            app(ApprovalService::class)->resolve($approval->id, $this->manager->id, 'approved');
        } finally {
            $this->assertSame('pending', EmployeeLeaveRequest::findOrFail($id)->status);
        }
    }

    public function test_the_employee_cannot_approve_their_own_leave_from_back_office(): void
    {
        [$staffManager] = $this->userWithToken('manager');
        $this->employee->update(['user_id' => $staffManager->id]);
        $id = $this->pushPending();
        $approval = $this->raiseApproval($id);

        $this->expectException(\RuntimeException::class);
        try {
            app(ApprovalService::class)->resolve($approval->id, $staffManager->id, 'approved');
        } finally {
            $this->assertSame(0, EmployeeLeaveEntry::count());
        }
    }

    public function test_rejection_posts_nothing(): void
    {
        $id = $this->pushPending();
        $approval = $this->raiseApproval($id);

        app(ApprovalService::class)->resolve($approval->id, $this->manager->id, 'rejected', 'Stocktake week');

        $leave = EmployeeLeaveRequest::findOrFail($id);
        $this->assertSame('rejected', $leave->status);
        $this->assertSame('Stocktake week', $leave->decision_note);
        $this->assertSame(0, EmployeeLeaveEntry::count());
    }

    public function test_a_till_decision_on_the_inbox_row_decides_the_leave(): void
    {
        $id = $this->pushPending();
        $approval = $this->raiseApproval($id);

        $this->push([['table' => 'approval_requests', 'uuid' => $approval->id, 'payload' => [
            'business_id' => $this->tenantId,
            'subject_type' => LeaveRequestDecision::SUBJECT_TYPE,
            'subject_id' => $id,
            'action' => LeaveRequestDecision::ACTION,
            'requested_by_user_id' => $this->staff->id,
            'status' => 'approved',
            'approver_user_id' => $this->manager->id,
            'approved_at' => now()->toIso8601String(),
            'payload_json' => ['employee_name' => 'Tendai', 'days' => 3],
        ]]])->assertJsonCount(1, 'accepted');

        $this->assertSame('approved', EmployeeLeaveRequest::findOrFail($id)->status);
        $this->assertTrue(EmployeeLeaveEntry::whereKey(LeaveRequestDecision::ledgerEntryId($id))->exists());
    }

    public function test_a_cancellation_closing_the_inbox_row_is_not_a_rejection(): void
    {
        $id = $this->pushPending();
        $approval = $this->raiseApproval($id);
        $this->push([
            ['table' => 'employee_leave_requests', 'uuid' => $id, 'payload' => $this->leavePayload([
                'status' => 'cancelled',
                'cancelled_at' => '2026-11-12T09:00:00.000Z',
            ])],
            ['table' => 'approval_requests', 'uuid' => $approval->id, 'payload' => [
                'business_id' => $this->tenantId,
                'subject_type' => LeaveRequestDecision::SUBJECT_TYPE,
                'subject_id' => $id,
                'action' => LeaveRequestDecision::ACTION,
                'requested_by_user_id' => $this->staff->id,
                'status' => 'rejected',
                'approver_user_id' => $this->staff->id,
                'reason' => LeaveRequestDecision::CANCELLED_REASON,
            ]],
        ])->assertJsonCount(2, 'accepted');

        $this->assertSame('cancelled', EmployeeLeaveRequest::findOrFail($id)->status);
    }

    /** Same vector as the till's leave_requests_test.dart. */
    public function test_ledger_id_matches_the_till(): void
    {
        $this->assertSame(
            '4a5d7f1a-c762-5606-aaf1-cdc9765fe2f9',
            LeaveRequestDecision::ledgerEntryId('00000000-0000-4000-8000-000000000001'),
        );
    }
}
