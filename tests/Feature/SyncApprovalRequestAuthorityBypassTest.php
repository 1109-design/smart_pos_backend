<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Business;
use App\Models\Device;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\PurchaseOrderApprovalGate;
use App\Services\ApprovalService;
use App\Services\BusinessProvisioner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Enterprise approval-rule-engine audit follow-up — closes the gap the
 * previous two commits (separation-of-duties, rule-based required-role)
 * left open: both live only in ApprovalService::resolve(), called only by
 * ApprovalsController (BackOffice web). A device can resolve the exact
 * same approval_requests row by pushing status: 'approved'/'rejected'
 * directly to /api/v1/sync/push instead — the till's own PIN-approved and
 * queued flows both write through this generic path — completely
 * bypassing ApprovalService::resolve() and, with it, every check added
 * there. This proves that bypass is now closed too.
 */
class SyncApprovalRequestAuthorityBypassTest extends TestCase
{
    use RefreshDatabase;

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

    private function pushApprovalDecision(string $token, string $requestId, string $status): TestResponse
    {
        $request = ApprovalRequest::findOrFail($requestId);

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'approval_requests',
                    'uuid' => $requestId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $request->business_id,
                        'subject_type' => $request->subject_type,
                        'subject_id' => $request->subject_id,
                        'action' => $request->action,
                        'requested_by_user_id' => $request->requested_by_user_id,
                        'status' => $status,
                        'reason' => null,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_a_till_cannot_directly_sync_push_approval_of_its_own_request(): void
    {
        $tenantId = 'tenant-sync-approval-bypass-1';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $requester = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-requester@example.com']);
        $requester->assignRole('manager');
        $token = $this->actingDeviceToken($tenantId, $requester);

        $request = app(ApprovalService::class)->request(
            $tenantId,
            'Transaction',
            (string) Str::uuid(),
            'void_transaction',
            $requester->id,
            ['reason' => 'Self-raised while alone on shift'],
        );

        // Same device, same user — pushing its own request's resolution
        // directly, bypassing the BackOffice web path entirely.
        $response = $this->pushApprovalDecision($token, $request->id, 'approved');

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'approval_requests: you cannot approve or reject your own request.',
            $response->json('errors.0.reason'),
        );
        $this->assertTrue($request->fresh()->isPending());
    }

    public function test_a_different_till_user_can_sync_push_a_legitimate_approval(): void
    {
        $tenantId = 'tenant-sync-approval-bypass-2';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $requester = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-requester@example.com']);
        $requester->assignRole('cashier');

        $approver = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-approver@example.com']);
        $approver->assignRole('manager');
        $token = $this->actingDeviceToken($tenantId, $approver);

        $request = app(ApprovalService::class)->request(
            $tenantId,
            'Transaction',
            (string) Str::uuid(),
            'void_transaction',
            $requester->id,
            ['reason' => 'Customer changed their mind'],
        );

        $response = $this->pushApprovalDecision($token, $request->id, 'approved');

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_a_cashier_cannot_sync_push_approval_of_a_po_requiring_branch_manager_authority(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $tenant = app(BusinessProvisioner::class)->provision([
            'business_name' => 'Acme Retail '.Str::random(6),
            'owner_email' => Str::random(8).'@acme.com',
            'tier' => 'pro',
            'subscription_valid_until' => now()->addMonth(),
            'currency_code' => 'USD',
            'admin_name' => 'Ada Owner',
            'admin_pin' => '4321',
        ]);

        $requester = User::factory()->create(['business_id' => $tenant->id, 'email' => Str::random(8).'@x.com']);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenant->id, 'name' => 'Acme Supplies']);
        $po = PurchaseOrder::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenant->id, 'supplier_id' => $supplier->id,
            'po_number' => 'PO-000001', 'status' => 'pending_approval', 'total_ordered' => 5000,
            'created_by_user_id' => $requester->id,
        ]);
        app(PurchaseOrderApprovalGate::class)->requestApproval($po->id);
        $request = ApprovalRequest::where('subject_id', $po->id)->firstOrFail();

        $cashier = User::factory()->create(['business_id' => $tenant->id, 'email' => Str::random(8).'@x.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenant->id, $cashier);

        $response = $this->pushApprovalDecision($token, $request->id, 'approved');

        $this->assertCount(0, $response->json('accepted'));
        $this->assertTrue($request->fresh()->isPending());
    }

    public function test_a_trusted_backoffice_resolution_is_not_double_gated(): void
    {
        $tenantId = 'tenant-sync-approval-bypass-4';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);

        $requester = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-requester@example.com']);
        $approver = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-approver@example.com']);

        $request = app(ApprovalService::class)->request(
            $tenantId,
            'Transaction',
            (string) Str::uuid(),
            'void_transaction',
            $requester->id,
            ['reason' => 'Customer changed their mind'],
        );

        // Mirrors ApprovalsController::approve() — trusted: true, already
        // vetted by ApprovalService::resolve()'s own canApprove() call
        // before this ever reaches SyncProcessor.
        $resolved = app(ApprovalService::class)->resolve($request->id, $approver->id, 'approved');

        $this->assertSame('approved', $resolved->status);
    }
}
