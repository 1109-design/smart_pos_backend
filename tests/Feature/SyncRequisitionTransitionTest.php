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
 * Two-till concurrency audit follow-up: a requisition is raised on one
 * device and approved/issued from another (manager PC, warehouse till) —
 * same multi-device shape as PurchaseOrder/StockTransfer, but had no
 * domain-aware transition guard. A device that requested a requisition and
 * went offline could resync its own stale 'pending' snapshot after another
 * device already approved/issued/rejected it. Mirrors
 * SyncPurchaseOrderTransitionTest / SyncStockTransferTransitionTest.
 */
class SyncRequisitionTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => '88888888-8888-4888-8888-888888888888',
            'email' => 'sync-requisition-owner@example.com',
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

    private function pushRequisition(string $token, string $tenantId, string $requisitionId, string $status): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'requisitions',
                    'uuid' => $requisitionId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'requisition_number' => 'REQ-000001',
                        'location_id' => '11111111-1111-4111-8111-111111111111',
                        'purpose' => 'general',
                        'status' => $status,
                        'requested_by_user_id' => '88888888-8888-4888-8888-888888888888',
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_normal_lifecycle_is_allowed(): void
    {
        $tenantId = 'tenant-req-1';
        $token = $this->actingDeviceToken($tenantId);
        $requisitionId = (string) Str::uuid();

        $this->pushRequisition($token, $tenantId, $requisitionId, 'pending')->assertOk();
        $this->pushRequisition($token, $tenantId, $requisitionId, 'approved')->assertOk();
        $this->pushRequisition($token, $tenantId, $requisitionId, 'issued')->assertOk();

        $this->assertDatabaseHas('requisitions', [
            'id' => $requisitionId,
            'status' => 'issued',
        ]);
    }

    public function test_an_issued_requisition_cannot_be_reopened(): void
    {
        $tenantId = 'tenant-req-2';
        $token = $this->actingDeviceToken($tenantId);
        $requisitionId = (string) Str::uuid();

        $this->pushRequisition($token, $tenantId, $requisitionId, 'pending')->assertOk();
        $this->pushRequisition($token, $tenantId, $requisitionId, 'approved')->assertOk();
        $this->pushRequisition($token, $tenantId, $requisitionId, 'issued')->assertOk();

        // Simulates the exact race the audit flagged: the requesting
        // device stayed offline since raising it, replaying its stale
        // 'pending' push after the warehouse till already issued it.
        $response = $this->pushRequisition($token, $tenantId, $requisitionId, 'pending');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('requisitions', [
            'id' => $requisitionId,
            'status' => 'issued',
        ]);
    }

    public function test_a_rejected_requisition_cannot_be_approved(): void
    {
        $tenantId = 'tenant-req-3';
        $token = $this->actingDeviceToken($tenantId);
        $requisitionId = (string) Str::uuid();

        $this->pushRequisition($token, $tenantId, $requisitionId, 'pending')->assertOk();
        $this->pushRequisition($token, $tenantId, $requisitionId, 'rejected')->assertOk();

        $response = $this->pushRequisition($token, $tenantId, $requisitionId, 'approved');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('requisitions', [
            'id' => $requisitionId,
            'status' => 'rejected',
        ]);
    }

    public function test_resyncing_the_same_status_is_a_safe_no_op(): void
    {
        $tenantId = 'tenant-req-4';
        $token = $this->actingDeviceToken($tenantId);
        $requisitionId = (string) Str::uuid();

        $this->pushRequisition($token, $tenantId, $requisitionId, 'pending')->assertOk();
        $response = $this->pushRequisition($token, $tenantId, $requisitionId, 'pending');

        $response->assertOk();
        $response->assertJsonCount(0, 'errors');

        $this->assertDatabaseHas('requisitions', [
            'id' => $requisitionId,
            'status' => 'pending',
        ]);
    }
}
