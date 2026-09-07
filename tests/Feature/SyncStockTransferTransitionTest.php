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
 * Offline-first audit follow-up: stock transfers had no domain-aware
 * transition guard (unlike stock takes), relying solely on the generic
 * timestamp conflict gate — see StockTransfer::isValidTransition().
 */
class SyncStockTransferTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => '88888888-8888-4888-8888-888888888888',
            'email' => 'sync-transfer-owner@example.com',
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

    private function pushTransfer(string $token, string $tenantId, string $transferId, string $status): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_transfers',
                    'uuid' => $transferId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'transfer_number' => 'TR-000001',
                        'from_location_id' => '11111111-1111-4111-8111-111111111111',
                        'to_location_id' => '22222222-2222-4222-8222-222222222222',
                        'status' => $status,
                        'requested_by_user_id' => '88888888-8888-4888-8888-888888888888',
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_dispatch_is_allowed_directly_from_pending(): void
    {
        $tenantId = 'tenant-transfer-1';
        $token = $this->actingDeviceToken($tenantId);
        $transferId = (string) Str::uuid();

        $this->pushTransfer($token, $tenantId, $transferId, 'pending')->assertOk();
        $this->pushTransfer($token, $tenantId, $transferId, 'in_transit')->assertOk();

        $this->assertDatabaseHas('stock_transfers', [
            'id' => $transferId,
            'status' => 'in_transit',
        ]);
    }

    public function test_cannot_skip_straight_from_pending_to_received(): void
    {
        $tenantId = 'tenant-transfer-2';
        $token = $this->actingDeviceToken($tenantId);
        $transferId = (string) Str::uuid();

        $this->pushTransfer($token, $tenantId, $transferId, 'pending')->assertOk();

        $response = $this->pushTransfer($token, $tenantId, $transferId, 'received');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('stock_transfers', [
            'id' => $transferId,
            'status' => 'pending',
        ]);
    }

    public function test_a_received_transfer_cannot_be_reopened(): void
    {
        $tenantId = 'tenant-transfer-3';
        $token = $this->actingDeviceToken($tenantId);
        $transferId = (string) Str::uuid();

        $this->pushTransfer($token, $tenantId, $transferId, 'pending')->assertOk();
        $this->pushTransfer($token, $tenantId, $transferId, 'in_transit')->assertOk();
        $this->pushTransfer($token, $tenantId, $transferId, 'received')->assertOk();

        // Simulates the exact race the audit flagged: a second device that
        // dispatched offline before the first device's "received" push
        // reached the server, now replaying its own "in_transit" push late.
        $response = $this->pushTransfer($token, $tenantId, $transferId, 'in_transit');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('stock_transfers', [
            'id' => $transferId,
            'status' => 'received',
        ]);
    }

    public function test_a_cancelled_transfer_cannot_be_dispatched(): void
    {
        $tenantId = 'tenant-transfer-4';
        $token = $this->actingDeviceToken($tenantId);
        $transferId = (string) Str::uuid();

        $this->pushTransfer($token, $tenantId, $transferId, 'pending')->assertOk();
        $this->pushTransfer($token, $tenantId, $transferId, 'cancelled')->assertOk();

        $response = $this->pushTransfer($token, $tenantId, $transferId, 'in_transit');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('stock_transfers', [
            'id' => $transferId,
            'status' => 'cancelled',
        ]);
    }
}
