<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Location;
use App\Models\Product;
use App\Models\Requisition;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Requisition approval is decided in the app; the server only relays the
 * resulting rows. It used to reject a 'requisition_issue' stock movement
 * unless the server's copy of the requisition said 'approved' — but a
 * device that approves then issues before its next push only ever sends
 * the final 'issued' row, so on 2026-10-10 REQ-20261010-002's status and
 * its three stock deductions were all rejected and never reached any other
 * device. These tests pin the relay behaviour.
 */
class RequisitionIssueSyncTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): array
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

        return [$plain, $user->id];
    }

    private function push(string $token, array $record): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [$record]]);
    }

    /** Stock is recomputed purely from the ledger (see SyncStockMovementTest), so an opening balance needs its own movement. */
    private function seedOpeningBalance(string $tenantId, string $productId, float $qty): void
    {
        StockMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'product_id' => $productId,
            'type' => 'adjustment',
            'quantity_change' => $qty,
            'reason' => 'Opening balance',
        ]);
    }

    public function test_issuing_against_a_requisition_the_server_still_sees_as_pending_deducts_stock(): void
    {
        $tenantId = 'tenant-req-gate-pending';
        [$token, $userId] = $this->actingDeviceToken($tenantId);
        $locationId = (string) Str::uuid();
        Location::create(['id' => $locationId, 'business_id' => $tenantId, 'name' => 'Main']);
        $productId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $tenantId, 'name' => 'Cement 50kg', 'price' => 10, 'stock_quantity' => 100]);
        $this->seedOpeningBalance($tenantId, $productId, 100);
        $requisitionId = (string) Str::uuid();
        Requisition::create([
            'id' => $requisitionId,
            'business_id' => $tenantId,
            'requisition_number' => 'REQ-1',
            'location_id' => $locationId,
            'purpose' => 'general',
            'status' => 'pending',
            'requested_by_user_id' => $userId,
        ]);

        $response = $this->push($token, [
            'table' => 'stock_movements',
            'uuid' => (string) Str::uuid(),
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $tenantId,
                'product_id' => $productId,
                'type' => 'requisition_issue',
                'quantity_change' => -15,
                'reference_id' => $requisitionId,
            ],
            'updated_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertEquals(85, Product::find($productId)->stock_quantity);
    }

    public function test_a_requisition_can_go_straight_from_pending_to_issued(): void
    {
        $tenantId = 'tenant-req-pending-issued';
        [$token, $userId] = $this->actingDeviceToken($tenantId);
        $locationId = (string) Str::uuid();
        Location::create(['id' => $locationId, 'business_id' => $tenantId, 'name' => 'Main']);
        $requisitionId = (string) Str::uuid();
        Requisition::create([
            'id' => $requisitionId,
            'business_id' => $tenantId,
            'requisition_number' => 'REQ-1',
            'location_id' => $locationId,
            'purpose' => 'general',
            'status' => 'pending',
            'requested_by_user_id' => $userId,
        ]);

        $response = $this->push($token, [
            'table' => 'requisitions',
            'uuid' => $requisitionId,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $tenantId,
                'requisition_number' => 'REQ-1',
                'location_id' => $locationId,
                'purpose' => 'general',
                'status' => 'issued',
                'requested_by_user_id' => $userId,
                'approved_by_user_id' => $userId,
                'approved_at' => now()->toIso8601String(),
                'issued_by_user_id' => $userId,
                'issued_at' => now()->toIso8601String(),
            ],
            'updated_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertSame('issued', Requisition::find($requisitionId)->status);
    }

    public function test_an_issued_requisition_cannot_go_back_to_pending(): void
    {
        $tenantId = 'tenant-req-issued-terminal';
        [$token, $userId] = $this->actingDeviceToken($tenantId);
        $locationId = (string) Str::uuid();
        Location::create(['id' => $locationId, 'business_id' => $tenantId, 'name' => 'Main']);
        $requisitionId = (string) Str::uuid();
        Requisition::create([
            'id' => $requisitionId,
            'business_id' => $tenantId,
            'requisition_number' => 'REQ-1',
            'location_id' => $locationId,
            'purpose' => 'general',
            'status' => 'issued',
            'requested_by_user_id' => $userId,
        ]);

        $response = $this->push($token, [
            'table' => 'requisitions',
            'uuid' => $requisitionId,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $tenantId,
                'requisition_number' => 'REQ-1',
                'location_id' => $locationId,
                'purpose' => 'general',
                'status' => 'pending',
                'requested_by_user_id' => $userId,
            ],
            'updated_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame('issued', Requisition::find($requisitionId)->status);
    }

    public function test_issuing_against_a_requisition_not_yet_on_the_server_deducts_stock(): void
    {
        $tenantId = 'tenant-req-gate-missing';
        [$token] = $this->actingDeviceToken($tenantId);
        $productId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $tenantId, 'name' => 'Cement 50kg', 'price' => 10, 'stock_quantity' => 100]);
        $this->seedOpeningBalance($tenantId, $productId, 100);

        $response = $this->push($token, [
            'table' => 'stock_movements',
            'uuid' => (string) Str::uuid(),
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $tenantId,
                'product_id' => $productId,
                'type' => 'requisition_issue',
                'quantity_change' => -15,
                'reference_id' => (string) Str::uuid(),
            ],
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->assertCount(1, $response->json('accepted'));
        $this->assertEquals(85, Product::find($productId)->stock_quantity);
    }

    public function test_issuing_against_an_approved_requisition_succeeds(): void
    {
        $tenantId = 'tenant-req-gate-approved';
        [$token, $userId] = $this->actingDeviceToken($tenantId);
        $locationId = (string) Str::uuid();
        Location::create(['id' => $locationId, 'business_id' => $tenantId, 'name' => 'Main']);
        $productId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $tenantId, 'name' => 'Cement 50kg', 'price' => 10, 'stock_quantity' => 100]);
        $this->seedOpeningBalance($tenantId, $productId, 100);
        $requisitionId = (string) Str::uuid();
        Requisition::create([
            'id' => $requisitionId,
            'business_id' => $tenantId,
            'requisition_number' => 'REQ-1',
            'location_id' => $locationId,
            'purpose' => 'general',
            'status' => 'approved',
            'requested_by_user_id' => $userId,
            'approved_by_user_id' => $userId,
            'approved_at' => now(),
        ]);

        $response = $this->push($token, [
            'table' => 'stock_movements',
            'uuid' => (string) Str::uuid(),
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $tenantId,
                'product_id' => $productId,
                'type' => 'requisition_issue',
                'quantity_change' => -15,
                'reference_id' => $requisitionId,
            ],
            'updated_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertEquals(85, Product::find($productId)->stock_quantity);
    }

    public function test_a_trusted_server_side_write_deducts_stock(): void
    {
        $tenantId = 'tenant-req-gate-trusted';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $productId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $tenantId, 'name' => 'Cement 50kg', 'price' => 10, 'stock_quantity' => 100]);
        $this->seedOpeningBalance($tenantId, $productId, 100);

        app(SyncProcessor::class)->process(
            'stock_movements',
            (string) Str::uuid(),
            'upsert',
            [
                'business_id' => $tenantId,
                'product_id' => $productId,
                'type' => 'requisition_issue',
                'quantity_change' => -15,
                'reference_id' => (string) Str::uuid(),
            ],
            trusted: true,
        );

        $this->assertEquals(85, Product::find($productId)->stock_quantity);
    }
}
