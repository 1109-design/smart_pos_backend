<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 2026-09-29 sync audit finding C3: transfer_detail_screen.dart::
 * _confirmReceive() is gated only by local button visibility
 * (transfer.status == 'in_transit'), with no check that the resulting
 * transfer_out/transfer_in stock_movements pair hasn't already been posted.
 * Two staff devices at the destination, both offline, both still showing
 * 'in_transit' locally, can each independently confirm receipt and push
 * their own movement pair (fresh, unrelated uuids) for the same transfer
 * item — doubling real stock deducted from source and added to destination
 * for one physical transfer. Fixed server-side in SyncProcessor's
 * 'stock_movements' case: a second transfer_out/transfer_in for the same
 * (reference_id, product_id) is now rejected outright, mirroring the
 * existing requisition_issue approval gate.
 */
class SyncStockTransferDuplicateReceiveGuardTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create(['email' => $tenantId.'-owner@example.com']);
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

    private function pushMovement(string $token, string $uuid, string $tenantId, string $type, string $productId, string $transferId, float $qty): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => $uuid,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'product_id' => $productId,
                        'type' => $type,
                        'quantity_change' => $qty,
                        'reference_id' => $transferId,
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_a_second_devices_transfer_in_is_rejected_after_the_first_already_landed(): void
    {
        $tenantId = 'tenant-transfer-dupe-in';
        $token = $this->actingDeviceToken($tenantId);
        $productId = (string) Str::uuid();
        $transferId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $tenantId, 'name' => 'Widget', 'item_type' => 'product', 'price' => 10]);

        // Device A confirms receipt first.
        $first = $this->pushMovement($token, (string) Str::uuid(), $tenantId, 'transfer_in', $productId, $transferId, 20);
        $first->assertOk();
        $this->assertCount(1, $first->json('accepted'));

        // Device B, offline the whole time and still showing 'in_transit'
        // locally, also confirms receipt — a different movement uuid, same
        // transfer + product.
        $second = $this->pushMovement($token, (string) Str::uuid(), $tenantId, 'transfer_in', $productId, $transferId, 20);
        $second->assertOk();
        $this->assertCount(0, $second->json('accepted'));
        $this->assertCount(1, $second->json('errors'));
        $this->assertStringContainsString('refusing to post a duplicate', $second->json('errors.0.reason'));

        // Exactly one transfer_in movement landed, not two — stock isn't doubled.
        $this->assertSame(1, StockMovement::where('reference_id', $transferId)->where('type', 'transfer_in')->count());
    }

    public function test_a_second_devices_transfer_out_is_rejected_after_the_first_already_landed(): void
    {
        $tenantId = 'tenant-transfer-dupe-out';
        $token = $this->actingDeviceToken($tenantId);
        $productId = (string) Str::uuid();
        $transferId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $tenantId, 'name' => 'Widget', 'item_type' => 'product', 'price' => 10]);

        $first = $this->pushMovement($token, (string) Str::uuid(), $tenantId, 'transfer_out', $productId, $transferId, -20);
        $first->assertOk();
        $this->assertCount(1, $first->json('accepted'));

        $second = $this->pushMovement($token, (string) Str::uuid(), $tenantId, 'transfer_out', $productId, $transferId, -20);
        $second->assertOk();
        $this->assertCount(0, $second->json('accepted'));
        $this->assertCount(1, $second->json('errors'));

        $this->assertSame(1, StockMovement::where('reference_id', $transferId)->where('type', 'transfer_out')->count());
    }

    public function test_a_retry_of_the_same_movement_uuid_is_not_treated_as_a_duplicate(): void
    {
        $tenantId = 'tenant-transfer-dupe-retry';
        $token = $this->actingDeviceToken($tenantId);
        $productId = (string) Str::uuid();
        $transferId = (string) Str::uuid();
        $movementId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $tenantId, 'name' => 'Widget', 'item_type' => 'product', 'price' => 10]);

        $first = $this->pushMovement($token, $movementId, $tenantId, 'transfer_in', $productId, $transferId, 20);
        $first->assertOk();
        $this->assertCount(1, $first->json('accepted'));

        // A genuine retry (dropped response, resent with the same uuid) must
        // still succeed as a plain idempotent upsert, not be rejected.
        $second = $this->pushMovement($token, $movementId, $tenantId, 'transfer_in', $productId, $transferId, 20);
        $second->assertOk();
        $this->assertCount(1, $second->json('accepted'));
        $this->assertSame(1, StockMovement::where('reference_id', $transferId)->where('type', 'transfer_in')->count());
    }

    public function test_different_products_on_the_same_transfer_are_not_falsely_blocked(): void
    {
        $tenantId = 'tenant-transfer-dupe-multi-item';
        $token = $this->actingDeviceToken($tenantId);
        $productA = (string) Str::uuid();
        $productB = (string) Str::uuid();
        $transferId = (string) Str::uuid();
        Product::create(['id' => $productA, 'business_id' => $tenantId, 'name' => 'Widget A', 'item_type' => 'product', 'price' => 10]);
        Product::create(['id' => $productB, 'business_id' => $tenantId, 'name' => 'Widget B', 'item_type' => 'product', 'price' => 10]);

        $first = $this->pushMovement($token, (string) Str::uuid(), $tenantId, 'transfer_in', $productA, $transferId, 20);
        $first->assertOk();
        $this->assertCount(1, $first->json('accepted'));

        $second = $this->pushMovement($token, (string) Str::uuid(), $tenantId, 'transfer_in', $productB, $transferId, 5);
        $second->assertOk();
        $this->assertCount(1, $second->json('accepted'));

        $this->assertSame(2, StockMovement::where('reference_id', $transferId)->where('type', 'transfer_in')->count());
    }
}
