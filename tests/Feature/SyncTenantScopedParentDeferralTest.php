<?php

namespace Tests\Feature;

use App\Models\ApprovalGroupMember;
use App\Models\ApprovalRequestStageDecision;
use App\Models\ApprovalRule;
use App\Models\ApprovalRuleSet;
use App\Models\Device;
use App\Models\PendingSyncRecord;
use App\Models\SalaryPayment;
use App\Models\SupplierBank;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Live bug (production "Pending Conflicts" queue, 25 entries, all the same
 * pair repeating): `approval_rules` carries its own `business_id`, so it's
 * listed in SyncProcessor::TENANT_SCOPED_MODELS — and assertOwnership()
 * returns early for anything in that map, meaning it never reached
 * CHILD_SCOPED_MODELS' resolveParentOwner() check that gives every OTHER
 * child table graceful MissingParentRecordException deferral (see
 * SyncOutOfOrderEventsTest). A rule pushed before its rule_set_id parent had
 * landed (two devices auto-provisioning defaults in different orders) hit a
 * raw SQLSTATE 1452 foreign-key violation instead — a permanent manual
 * conflict that never self-heals even once the parent does arrive.
 *
 * Fixed via TENANT_SCOPED_PARENT_CHECKS + assertTenantScopedParentExists():
 * the same existence/ownership check CHILD_SCOPED_MODELS gets, layered onto
 * every TENANT_SCOPED_MODELS table that also carries a real FK to another
 * synced table. Five tables were live-confirmed at risk (their parent table
 * has exactly one referencing FK, verified against information_schema):
 * approval_rules, approval_group_members, approval_request_stage_decisions,
 * supplier_banks, salary_payments.
 */
class SyncTenantScopedParentDeferralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingDeviceToken(string $tenantId, User $user): string
    {
        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function push(string $token, string $table, string $uuid, array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => $table,
                'uuid' => $uuid,
                'operation' => 'upsert',
                'payload' => $payload,
                'updated_at' => now()->toIso8601String(),
            ]]]);
    }

    public function test_approval_rules_pushed_before_its_rule_set_defers_then_self_heals(): void
    {
        $tenantId = 'tenant-defer-approval-rules';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $ruleSetId = (string) Str::uuid();
        $ruleId = (string) Str::uuid();

        // The child arrives first — its parent doesn't exist on the server yet.
        $respRule = $this->push($token, 'approval_rules', $ruleId, [
            'business_id' => $tenantId,
            'rule_set_id' => $ruleSetId,
            'level' => 1,
            'required_role' => 'manager',
            'min_approvers' => 1,
            'is_sequential' => true,
            'sla_hours' => 24,
            'require_different_user' => true,
        ]);

        $respRule->assertOk();
        $this->assertCount(0, $respRule->json('accepted'));
        $this->assertCount(0, $respRule->json('errors'), 'must not be a hard processing_error');
        $this->assertCount(1, $respRule->json('deferred'), 'must be queued for automatic replay, exactly like a CHILD_SCOPED_MODELS table');
        $this->assertStringContainsString('referenced parent does not exist yet', $respRule->json('deferred.0.reason'));
        $this->assertSame(0, ApprovalRule::where('id', $ruleId)->count());
        $this->assertSame(1, PendingSyncRecord::where('record_uuid', $ruleId)->count());

        // The parent lands — this push's own resolvePendingRecords() pass
        // must replay the deferred rule automatically, no client retry.
        $respSet = $this->push($token, 'approval_rule_sets', $ruleSetId, [
            'business_id' => $tenantId,
            'process' => 'purchase_order',
            'name' => 'Purchase Order Approval',
            'is_enabled' => true,
        ]);

        $respSet->assertOk();
        $this->assertCount(1, $respSet->json('accepted'));
        $this->assertCount(1, $respSet->json('resolved_pending'));
        $this->assertSame($ruleId, $respSet->json('resolved_pending.0.uuid'));
        $this->assertSame(1, ApprovalRule::where('id', $ruleId)->where('rule_set_id', $ruleSetId)->count());
        $this->assertSame(0, PendingSyncRecord::where('record_uuid', $ruleId)->count());
    }

    public function test_approval_rules_referencing_another_businesss_rule_set_still_hard_fails(): void
    {
        $tenantId = 'tenant-defer-cross-tenant';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $otherTenantId = 'tenant-defer-cross-tenant-other';
        Tenant::create(['id' => $otherTenantId, 'business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com']);
        $otherRuleSetId = (string) Str::uuid();
        ApprovalRuleSet::create([
            'id' => $otherRuleSetId,
            'business_id' => $otherTenantId,
            'process' => 'purchase_order',
            'name' => 'Other business rule set',
            'is_enabled' => true,
        ]);

        $resp = $this->push($token, 'approval_rules', (string) Str::uuid(), [
            'business_id' => $tenantId,
            'rule_set_id' => $otherRuleSetId,
            'level' => 1,
            'required_role' => 'manager',
        ]);

        $resp->assertOk();
        $this->assertCount(0, $resp->json('deferred'), 'a genuine cross-tenant reference must not be treated as merely out-of-order');
        $this->assertCount(1, $resp->json('errors'));
        $this->assertStringContainsString('does not belong to this business', $resp->json('errors.0.reason'));
        $this->assertSame(0, PendingSyncRecord::count());
    }

    public function test_approval_group_members_pushed_before_its_group_defers_then_self_heals(): void
    {
        $tenantId = 'tenant-defer-group-members';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $groupId = (string) Str::uuid();
        $memberId = (string) Str::uuid();
        $memberUserId = (string) Str::uuid();

        $respMember = $this->push($token, 'approval_group_members', $memberId, [
            'business_id' => $tenantId,
            'group_id' => $groupId,
            'user_id' => $memberUserId,
        ]);
        $respMember->assertOk();
        $this->assertCount(1, $respMember->json('deferred'));
        $this->assertCount(0, $respMember->json('errors'));

        $respGroup = $this->push($token, 'approval_groups', $groupId, [
            'business_id' => $tenantId,
            'name' => 'Finance approvers',
        ]);
        $respGroup->assertOk();
        $this->assertCount(1, $respGroup->json('resolved_pending'));
        $this->assertSame(1, ApprovalGroupMember::where('id', $memberId)->where('group_id', $groupId)->count());
    }

    public function test_approval_request_stage_decision_pushed_before_its_request_defers_then_self_heals(): void
    {
        $tenantId = 'tenant-defer-stage-decision';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $approver = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-approver@example.com']);
        $approver->assignRole('manager');
        $token = $this->actingDeviceToken($tenantId, $approver);

        $requestId = (string) Str::uuid();
        $decisionId = (string) Str::uuid();

        $respDecision = $this->push($token, 'approval_request_stage_decisions', $decisionId, [
            'business_id' => $tenantId,
            'approval_request_id' => $requestId,
            'level' => 1,
            'decision' => 'approved',
            'acted_by_user_id' => $approver->id,
        ]);
        $respDecision->assertOk();
        $this->assertCount(1, $respDecision->json('deferred'));
        $this->assertCount(0, $respDecision->json('errors'));

        $respRequest = $this->push($token, 'approval_requests', $requestId, [
            'business_id' => $tenantId,
            'subject_type' => 'purchase_order',
            'subject_id' => (string) Str::uuid(),
            'action' => 'approve',
            'requested_by_user_id' => (string) Str::uuid(),
            'status' => 'pending',
        ]);
        $respRequest->assertOk();
        $this->assertCount(1, $respRequest->json('resolved_pending'));
        $this->assertSame(1, ApprovalRequestStageDecision::where('id', $decisionId)->where('approval_request_id', $requestId)->count());
    }

    public function test_supplier_bank_pushed_before_its_supplier_defers_then_self_heals(): void
    {
        $tenantId = 'tenant-defer-supplier-bank';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $supplierId = (string) Str::uuid();
        $bankId = (string) Str::uuid();

        $respBank = $this->push($token, 'supplier_banks', $bankId, [
            'business_id' => $tenantId,
            'supplier_id' => $supplierId,
            'bank_name' => 'CBZ',
            'account_number' => '123456',
        ]);
        $respBank->assertOk();
        $this->assertCount(1, $respBank->json('deferred'));
        $this->assertCount(0, $respBank->json('errors'));

        $respSupplier = $this->push($token, 'suppliers', $supplierId, [
            'business_id' => $tenantId,
            'name' => 'Acme Distributors',
        ]);
        $respSupplier->assertOk();
        $this->assertCount(1, $respSupplier->json('resolved_pending'));
        $this->assertSame(1, SupplierBank::where('id', $bankId)->where('supplier_id', $supplierId)->count());
    }

    public function test_salary_payment_pushed_before_its_employee_defers_then_self_heals(): void
    {
        $tenantId = 'tenant-defer-salary-payment';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $employeeId = (string) Str::uuid();
        $paymentId = (string) Str::uuid();

        $respPayment = $this->push($token, 'salary_payments', $paymentId, [
            'business_id' => $tenantId,
            'employee_id' => $employeeId,
            'period' => '2026-09',
            'amount' => 500,
            'currency_code' => 'USD',
            'base_equivalent' => 500,
            'paid_by_user_id' => $owner->id,
            'paid_at' => now()->toIso8601String(),
        ]);
        $respPayment->assertOk();
        $this->assertCount(1, $respPayment->json('deferred'));
        $this->assertCount(0, $respPayment->json('errors'));

        $respEmployee = $this->push($token, 'employees', $employeeId, [
            'business_id' => $tenantId,
            'name' => 'Jane Doe',
            'pay_type' => 'salary',
            'salary_amount' => 500,
            'currency_code' => 'USD',
            'hire_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $respEmployee->assertOk();
        $this->assertCount(1, $respEmployee->json('resolved_pending'));
        $this->assertSame(1, SalaryPayment::where('id', $paymentId)->where('employee_id', $employeeId)->count());
    }

    public function test_deferred_child_record_does_not_create_duplicate_pending_rows_on_resend(): void
    {
        $tenantId = 'tenant-defer-dedup';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $employeeId = (string) Str::uuid();
        $paymentId = (string) Str::uuid();

        // Push 1: deferred because employee does not exist
        $resp1 = $this->push($token, 'salary_payments', $paymentId, [
            'business_id' => $tenantId,
            'employee_id' => $employeeId,
            'period' => '2026-09',
            'amount' => 500,
            'currency_code' => 'USD',
            'base_equivalent' => 500,
            'paid_by_user_id' => $owner->id,
            'paid_at' => now()->toIso8601String(),
        ]);
        $resp1->assertOk();
        $this->assertCount(1, $resp1->json('deferred'));
        $this->assertSame(1, PendingSyncRecord::where('record_uuid', $paymentId)->count());

        // Push 2: 10s later, still blocked — must reuse existing pending row, not create duplicate
        $resp2 = $this->push($token, 'salary_payments', $paymentId, [
            'business_id' => $tenantId,
            'employee_id' => $employeeId,
            'period' => '2026-09',
            'amount' => 500,
            'currency_code' => 'USD',
            'base_equivalent' => 500,
            'paid_by_user_id' => $owner->id,
            'paid_at' => now()->toIso8601String(),
        ]);
        $resp2->assertOk();
        $this->assertCount(1, $resp2->json('deferred'));
        $this->assertSame(1, PendingSyncRecord::where('record_uuid', $paymentId)->count(), 'deferred record must be deduplicated by (business_id, table, uuid)');
    }
}

