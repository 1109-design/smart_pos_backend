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
 * Two-till concurrency audit follow-up: a PO is genuinely multi-device
 * (created/sent on one till, received via GRV on another — often a
 * warehouse till). gatePurchaseOrderStatus() already locked
 * 'pending_approval' against being overwritten, but applied every other
 * incoming status unconditionally, so a device that sent a PO and went
 * offline could resync its own stale 'sent' snapshot after another device
 * already received (or cancelled) it. Mirrors SyncStockTransferTransitionTest
 * / SyncBankReconciliationTransitionTest / SyncAssetDisposalTransitionTest.
 */
class SyncPurchaseOrderTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => '88888888-8888-4888-8888-888888888888',
            'email' => 'sync-po-owner@example.com',
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

    private function pushPo(string $token, string $tenantId, string $poId, string $status): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'purchase_orders',
                    'uuid' => $poId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'po_number' => 'PO-000001',
                        'status' => $status,
                        'total_ordered' => 100,
                        'created_by_user_id' => '88888888-8888-4888-8888-888888888888',
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_normal_lifecycle_is_allowed(): void
    {
        $tenantId = 'tenant-po-1';
        $token = $this->actingDeviceToken($tenantId);
        $poId = (string) Str::uuid();

        $this->pushPo($token, $tenantId, $poId, 'draft')->assertOk();
        $this->pushPo($token, $tenantId, $poId, 'sent')->assertOk();
        $this->pushPo($token, $tenantId, $poId, 'partial')->assertOk();
        $this->pushPo($token, $tenantId, $poId, 'received')->assertOk();

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'status' => 'received',
        ]);
    }

    public function test_a_received_po_cannot_be_reopened(): void
    {
        $tenantId = 'tenant-po-2';
        $token = $this->actingDeviceToken($tenantId);
        $poId = (string) Str::uuid();

        $this->pushPo($token, $tenantId, $poId, 'draft')->assertOk();
        $this->pushPo($token, $tenantId, $poId, 'sent')->assertOk();
        $this->pushPo($token, $tenantId, $poId, 'received')->assertOk();

        // Simulates the exact race the audit flagged: the creating device
        // stayed offline since sending the PO, replaying its stale 'sent'
        // push after the warehouse till already received it in full.
        $response = $this->pushPo($token, $tenantId, $poId, 'sent');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'status' => 'received',
        ]);
    }

    public function test_a_cancelled_po_cannot_be_sent(): void
    {
        $tenantId = 'tenant-po-3';
        $token = $this->actingDeviceToken($tenantId);
        $poId = (string) Str::uuid();

        $this->pushPo($token, $tenantId, $poId, 'draft')->assertOk();
        $this->pushPo($token, $tenantId, $poId, 'cancelled')->assertOk();

        $response = $this->pushPo($token, $tenantId, $poId, 'sent');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'status' => 'cancelled',
        ]);
    }

    public function test_resyncing_the_same_status_is_a_safe_no_op(): void
    {
        $tenantId = 'tenant-po-4';
        $token = $this->actingDeviceToken($tenantId);
        $poId = (string) Str::uuid();

        $this->pushPo($token, $tenantId, $poId, 'sent')->assertOk();
        $response = $this->pushPo($token, $tenantId, $poId, 'sent');

        $response->assertOk();
        $response->assertJsonCount(0, 'errors');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'status' => 'sent',
        ]);
    }
}
