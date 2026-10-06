<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Device;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A customer return (`refund_transaction`) may only be decided by someone
 * holding the till's `approveSalesReturn` permission — the same rule the
 * till's PIN check and its own Approvals inbox apply. Covers both decision
 * paths: BackOffice (ApprovalService::resolve) and a device's sync push.
 */
class ApprovalReturnPermissionTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-return-permission';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::create(['id' => $this->tenantId, 'business_name' => $this->tenantId, 'owner_email' => $this->tenantId.'@example.com']);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create([
            'business_id' => $this->tenantId,
            'email' => $this->tenantId.'-'.Str::random(6).'@example.com',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function grant(string $role, array $permissions): void
    {
        RolePermission::updateOrCreate(
            ['business_id' => $this->tenantId, 'role' => $role],
            ['permissions_json' => $permissions],
        );
    }

    private function returnRequest(string $action = 'refund_transaction'): ApprovalRequest
    {
        return app(ApprovalService::class)->request(
            $this->tenantId,
            'Transaction',
            (string) Str::uuid(),
            $action,
            $this->user('cashier')->id,
            ['reason' => 'Wrong size', 'amount' => 20],
        );
    }

    private function push(User $user, ApprovalRequest $request, string $status): TestResponse
    {
        $plain = $user->createToken('sync-test')->plainTextToken;
        Device::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'approval_requests',
                    'uuid' => $request->id,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $request->business_id,
                        'subject_type' => $request->subject_type,
                        'subject_id' => $request->subject_id,
                        'action' => $request->action,
                        'requested_by_user_id' => $request->requested_by_user_id,
                        'status' => $status,
                        'reason' => $status === 'rejected' ? 'No receipt' : null,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_a_manager_without_the_permission_cannot_approve_a_return(): void
    {
        $this->grant('manager', ['issueRefund']);
        $request = $this->returnRequest();

        $this->expectExceptionMessage('approveSalesReturn');
        app(ApprovalService::class)->resolve($request->id, $this->user('manager')->id, 'approved');
    }

    public function test_a_manager_with_the_permission_can_approve_a_return(): void
    {
        $this->grant('manager', ['issueRefund', 'approveSalesReturn']);
        $request = $this->returnRequest();

        app(ApprovalService::class)->resolve($request->id, $this->user('manager')->id, 'approved');

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_a_manager_role_never_customised_gets_the_till_default(): void
    {
        $request = $this->returnRequest();

        app(ApprovalService::class)->resolve($request->id, $this->user('manager')->id, 'approved');

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_the_owner_can_always_approve_a_return(): void
    {
        $this->grant('business_owner', []);
        $request = $this->returnRequest();

        app(ApprovalService::class)->resolve($request->id, $this->user('business_owner')->id, 'approved');

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_other_actions_are_not_affected(): void
    {
        $this->grant('manager', ['issueRefund']);
        $request = $this->returnRequest('void_transaction');

        app(ApprovalService::class)->resolve($request->id, $this->user('manager')->id, 'approved');

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_a_sync_pushed_decision_is_never_re_gated(): void
    {
        // The app checked the approver's permission when they decided; the
        // device token's owner (who the server sees) may well lack it.
        $this->grant('manager', ['issueRefund']);
        $request = $this->returnRequest();

        $response = $this->push($this->user('manager'), $request, 'approved');

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_a_sync_pushed_decision_with_the_permission_is_accepted(): void
    {
        $this->grant('manager', ['issueRefund', 'approveSalesReturn']);
        $request = $this->returnRequest();

        $response = $this->push($this->user('manager'), $request, 'approved');

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertSame('approved', $request->fresh()->status);
    }
}
