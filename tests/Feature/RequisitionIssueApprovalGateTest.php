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
 * Master spec STK·03 / section 38: "A requisition alone must NEVER remove
 * stock" and "security must be enforced beyond the UI." Unlike void/refund/
 * exchange-rate changes (gated by SyncProcessor::hasApprovedRequest() against
 * the generic approval_requests table), a requisition's approval lives on
 * its own Requisition::status column — nothing previously stopped a device
 * that talks to /api/v1/sync/push directly (bypassing the Flutter app's own
 * "Issue" button, which is only shown once status=='approved') from pushing
 * a stock_movements row of type 'requisition_issue' against a requisition
 * that was never approved, silently deducting real stock. Found via a live
 * exercise of the running dev server, not code reading — reproduced, then
 * fixed with a gate in SyncProcessor::process()'s 'stock_movements' case.
 */
class RequisitionIssueApprovalGateTest extends TestCase
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

    public function test_issuing_against_a_pending_requisition_is_rejected_and_stock_is_unchanged(): void
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
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'stock_movements: requisition_issue requires an approved requisition.',
            $response->json('errors.0.reason'),
        );
        $this->assertEquals(100, Product::find($productId)->stock_quantity);
    }

    public function test_issuing_against_a_nonexistent_requisition_is_rejected(): void
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

        $this->assertCount(0, $response->json('accepted'));
        $this->assertEquals(100, Product::find($productId)->stock_quantity);
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

    public function test_a_trusted_server_side_write_is_not_gated(): void
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
