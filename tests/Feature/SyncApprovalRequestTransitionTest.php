<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Two-till concurrency audit follow-up: an approval request is raised on
 * one device and resolved from another (BackOffice, or a manager's own
 * device), but had no domain-aware transition guard. A device that raised
 * a request and went offline could resync its own stale 'pending' creation
 * payload after another device already approved/rejected it — which would
 * then let ApprovalService::resolve() run applyApprovedAction() a second
 * time. That isn't idempotent for every action (change_exchange_rate closes
 * out the previously-current rate and opens a new one on every call), so a
 * second resolution would corrupt the FX rate history. Mirrors
 * SyncPurchaseOrderTransitionTest / SyncRequisitionTransitionTest.
 */
class SyncApprovalRequestTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => '88888888-8888-4888-8888-888888888888',
            'email' => 'sync-approval-owner@example.com',
        ]);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function pushApproval(string $token, string $tenantId, string $requestId, string $status): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'approval_requests',
                    'uuid' => $requestId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'subject_type' => 'Discount',
                        'subject_id' => (string) Str::uuid(),
                        'action' => 'apply_discount',
                        'requested_by_user_id' => '88888888-8888-4888-8888-888888888888',
                        'status' => $status,
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_approving_directly_from_pending_is_allowed(): void
    {
        $tenantId = 'tenant-approval-1';
        $token = $this->actingDeviceToken($tenantId);
        $requestId = (string) Str::uuid();

        $this->pushApproval($token, $tenantId, $requestId, 'pending')->assertOk();
        $this->pushApproval($token, $tenantId, $requestId, 'approved')->assertOk();

        $this->assertDatabaseHas('approval_requests', [
            'id' => $requestId,
            'status' => 'approved',
        ]);
    }

    public function test_an_approved_request_cannot_be_reopened(): void
    {
        $tenantId = 'tenant-approval-2';
        $token = $this->actingDeviceToken($tenantId);
        $requestId = (string) Str::uuid();

        $this->pushApproval($token, $tenantId, $requestId, 'pending')->assertOk();
        $this->pushApproval($token, $tenantId, $requestId, 'approved')->assertOk();

        // Simulates the exact race the audit flagged: the requesting
        // device stayed offline since raising it, replaying its stale
        // 'pending' creation push after another device already approved it.
        $response = $this->pushApproval($token, $tenantId, $requestId, 'pending');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('approval_requests', [
            'id' => $requestId,
            'status' => 'approved',
        ]);
    }

    public function test_a_rejected_request_cannot_be_approved(): void
    {
        $tenantId = 'tenant-approval-3';
        $token = $this->actingDeviceToken($tenantId);
        $requestId = (string) Str::uuid();

        $this->pushApproval($token, $tenantId, $requestId, 'pending')->assertOk();
        $this->pushApproval($token, $tenantId, $requestId, 'rejected')->assertOk();

        $response = $this->pushApproval($token, $tenantId, $requestId, 'approved');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('approval_requests', [
            'id' => $requestId,
            'status' => 'rejected',
        ]);
    }

    public function test_resyncing_the_same_status_is_a_safe_no_op(): void
    {
        $tenantId = 'tenant-approval-4';
        $token = $this->actingDeviceToken($tenantId);
        $requestId = (string) Str::uuid();

        $this->pushApproval($token, $tenantId, $requestId, 'pending')->assertOk();
        $response = $this->pushApproval($token, $tenantId, $requestId, 'pending');

        $response->assertOk();
        $response->assertJsonCount(0, 'errors');

        $this->assertDatabaseHas('approval_requests', [
            'id' => $requestId,
            'status' => 'pending',
        ]);
    }
}
