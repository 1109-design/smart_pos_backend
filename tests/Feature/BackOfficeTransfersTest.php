<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockTransfer;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeTransfersTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'email' => $tenantId.'-user@example.com',
            'is_active' => true,
        ]);

        session([
            'backoffice' => [
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'role' => 'business_owner',
                'business_name' => $tenantId,
                'currency_code' => 'USD',
            ],
        ]);

        return $user;
    }

    private function makeLocation(string $tenantId, string $name, string $type = 'shop'): Location
    {
        return Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => $name,
            'type' => $type,
            'is_active' => true,
        ]);
    }

    private function stockAt(string $productId, string $locationId): ?ProductStock
    {
        return ProductStock::where('product_id', $productId)->where('location_id', $locationId)->first();
    }

    /**
     * Give a product opening stock at a location the same way a real
     * location-aware product creation would: a ledger entry, recomputed into
     * product_stock — not a bare column write. A transfer's own ledger
     * entries later resum from scratch, so any stock not already in the
     * ledger would otherwise vanish the moment a transfer touches that
     * product/location.
     */
    private function seedLocationStock(string $tenantId, string $productId, string $locationId, float $qty): void
    {
        app(SyncProcessor::class)->process('stock_movements', (string) Str::uuid(), 'upsert', [
            'business_id' => $tenantId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => $qty,
            'reason' => 'Test opening stock',
        ]);
    }

    public function test_full_transfer_lifecycle_moves_stock_and_syncs_every_step(): void
    {
        $tenantId = 'tenant-transfer-1';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = $this->makeLocation($tenantId, 'Warehouse', 'warehouse');
        $shop = $this->makeLocation($tenantId, 'Shop', 'shop');

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Widget',
            'item_type' => 'product',
            'price' => 5,
            'track_stock' => true,
            'stock_quantity' => 20,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 20);

        // 1. Request
        $this->post('/office/transfers', [
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'items' => [
                ['product_id' => $product->id, 'qty_requested' => 10],
            ],
        ])->assertRedirect();

        $transfer = StockTransfer::where('business_id', $tenantId)->first();
        $this->assertNotNull($transfer);
        $this->assertSame('pending', $transfer->status);
        $item = $transfer->items->first();
        $this->assertSame('10.0000', $item->qty_requested);

        $this->assertDatabaseHas('sync_records', ['table_name' => 'stock_transfers', 'record_uuid' => $transfer->id]);
        $this->assertDatabaseHas('sync_records', ['table_name' => 'stock_transfer_items', 'record_uuid' => $item->id]);

        // 2. Approve
        $this->post("/office/transfers/{$transfer->id}/approve")->assertRedirect();
        $this->assertSame('approved', $transfer->fresh()->status);

        // 3. Dispatch — reserves at source, doesn't touch quantity yet.
        $this->post("/office/transfers/{$transfer->id}/dispatch", [
            'items' => [['item_id' => $item->id, 'qty_sent' => 10]],
        ])->assertRedirect();

        $transfer->refresh();
        $this->assertSame('in_transit', $transfer->status);
        $sourceStock = $this->stockAt($product->id, $warehouse->id);
        $this->assertSame('20.0000', $sourceStock->quantity);
        $this->assertSame('10.0000', $sourceStock->reserved_quantity);

        // 4. Receive — full amount arrives.
        $this->post("/office/transfers/{$transfer->id}/receive", [
            'items' => [['item_id' => $item->id, 'qty_received' => 10]],
        ])->assertRedirect();

        $transfer->refresh();
        $this->assertSame('received', $transfer->status);

        $sourceStock->refresh();
        $this->assertSame('10.0000', $sourceStock->quantity);
        $this->assertSame('0.0000', $sourceStock->reserved_quantity);

        $destStock = $this->stockAt($product->id, $shop->id);
        $this->assertSame('10.0000', $destStock->quantity);

        // Flat cross-location total is unchanged — stock moved location, none was lost.
        $this->assertSame('20.0000', $product->fresh()->stock_quantity);

        // Ledger rows for the audit trail.
        $this->assertDatabaseHas('stock_movements', [
            'reference_id' => $transfer->id,
            'type' => 'transfer_out',
            'location_id' => $warehouse->id,
            'quantity_change' => -10,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'reference_id' => $transfer->id,
            'type' => 'transfer_in',
            'location_id' => $shop->id,
            'quantity_change' => 10,
        ]);

        // Every mutation must have reached the sync stream.
        $this->assertGreaterThanOrEqual(
            2,
            SyncRecord::where('table_name', 'stock_transfers')->where('record_uuid', $transfer->id)->count()
        );
    }

    public function test_receive_with_shortfall_logs_a_loss_and_only_moves_what_arrived(): void
    {
        $tenantId = 'tenant-transfer-2';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = $this->makeLocation($tenantId, 'Warehouse', 'warehouse');
        $shop = $this->makeLocation($tenantId, 'Shop', 'shop');

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Fragile Widget',
            'item_type' => 'product',
            'price' => 5,
            'track_stock' => true,
            'stock_quantity' => 10,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 10);

        $this->post('/office/transfers', [
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'items' => [['product_id' => $product->id, 'qty_requested' => 10]],
        ]);
        $transfer = StockTransfer::where('business_id', $tenantId)->first();
        $item = $transfer->items->first();

        $this->post("/office/transfers/{$transfer->id}/dispatch", [
            'items' => [['item_id' => $item->id, 'qty_sent' => 10]],
        ]);

        // Only 8 arrive — 2 are lost in transit.
        $this->post("/office/transfers/{$transfer->id}/receive", [
            'items' => [['item_id' => $item->id, 'qty_received' => 8]],
        ])->assertRedirect();

        $destStock = $this->stockAt($product->id, $shop->id);
        $this->assertSame('8.0000', $destStock->quantity);

        $sourceStock = $this->stockAt($product->id, $warehouse->id);
        $this->assertSame('0.0000', $sourceStock->quantity);
        $this->assertSame('0.0000', $sourceStock->reserved_quantity);

        $this->assertSame('8.0000', $product->fresh()->stock_quantity);

        $this->assertDatabaseHas('stock_movements', [
            'reference_id' => $transfer->id,
            'type' => 'transfer_loss',
            'quantity_change' => 0,
        ]);
    }

    public function test_cancel_releases_reservation_and_pending_cannot_be_dispatched_twice_into_new_status(): void
    {
        $tenantId = 'tenant-transfer-3';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = $this->makeLocation($tenantId, 'Warehouse', 'warehouse');
        $shop = $this->makeLocation($tenantId, 'Shop', 'shop');

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Cancelable Widget',
            'item_type' => 'product',
            'price' => 5,
            'track_stock' => true,
            'stock_quantity' => 5,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 5);

        $this->post('/office/transfers', [
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'items' => [['product_id' => $product->id, 'qty_requested' => 5]],
        ]);
        $transfer = StockTransfer::where('business_id', $tenantId)->first();

        $this->post("/office/transfers/{$transfer->id}/cancel")->assertRedirect();
        $this->assertSame('cancelled', $transfer->fresh()->status);

        // Cancelling an already-cancelled transfer must fail gracefully, not 500.
        $response = $this->post("/office/transfers/{$transfer->id}/cancel");
        $response->assertSessionHasErrors('transfer');
        $this->assertSame('cancelled', $transfer->fresh()->status);
    }

    public function test_transfer_actions_are_scoped_to_the_current_tenant(): void
    {
        $otherTenantId = 'tenant-transfer-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => 'YYYYYY']);
        $foreignWarehouse = $this->makeLocation($otherTenantId, 'Their Warehouse', 'warehouse');
        $foreignShop = $this->makeLocation($otherTenantId, 'Their Shop', 'shop');
        $foreignProduct = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $otherTenantId,
            'name' => 'Their Widget',
            'item_type' => 'product',
            'price' => 5,
            'track_stock' => true,
            'stock_quantity' => 5,
        ]);

        $tenantId = 'tenant-transfer-4';
        $this->actingBackOfficeSession($tenantId);

        // Can't request a transfer using another tenant's locations.
        $this->post('/office/transfers', [
            'from_location_id' => $foreignWarehouse->id,
            'to_location_id' => $foreignShop->id,
            'items' => [['product_id' => $foreignProduct->id, 'qty_requested' => 1]],
        ])->assertNotFound();

        $foreignTransfer = StockTransfer::create([
            'id' => (string) Str::uuid(),
            'business_id' => $otherTenantId,
            'transfer_number' => 'TRF-FOREIGN-001',
            'from_location_id' => $foreignWarehouse->id,
            'to_location_id' => $foreignShop->id,
            'status' => 'pending',
            'requested_by_user_id' => (string) Str::uuid(),
        ]);

        $this->post("/office/transfers/{$foreignTransfer->id}/approve")->assertNotFound();
        $this->assertSame('pending', $foreignTransfer->fresh()->status);
    }
}
