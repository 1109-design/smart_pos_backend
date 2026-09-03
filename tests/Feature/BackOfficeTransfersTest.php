<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Business;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockTransfer;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LocationService;
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
        // Dispatch commits source stock via in_transit_quantity, not
        // reserved_quantity — that field is order-holds only (see the
        // dedicated in-transit tests below for the full picture).
        $this->assertSame('0.0000', $sourceStock->reserved_quantity);
        $this->assertSame('10.0000', $sourceStock->in_transit_quantity);

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

    public function test_dispatching_a_transfer_does_not_wipe_the_source_locations_price_override(): void
    {
        // Regression: LocationService::publishStock() used to build its sync
        // payload from only quantity/reserved_quantity, and SyncProcessor
        // treats a missing key as an explicit null — so any transfer touching
        // a product with a location override would silently null it out.
        $tenantId = 'tenant-transfer-override-1';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = $this->makeLocation($tenantId, 'Warehouse', 'warehouse');
        $shop = $this->makeLocation($tenantId, 'Shop', 'shop');

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Priced Widget',
            'item_type' => 'product',
            'price' => 5,
            'track_stock' => true,
            'stock_quantity' => 20,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 20);

        $this->post("/office/products/{$product->id}/location-overrides", [
            'location_id' => $warehouse->id,
            'price_override' => 4.5,
        ])->assertRedirect();

        $this->post('/office/transfers', [
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'items' => [['product_id' => $product->id, 'qty_requested' => 5]],
        ])->assertRedirect();
        $transfer = StockTransfer::where('business_id', $tenantId)->first();
        $item = $transfer->items->first();

        $this->post("/office/transfers/{$transfer->id}/dispatch", [
            'items' => [['item_id' => $item->id, 'qty_sent' => 5]],
        ])->assertRedirect();

        $this->assertSame('4.5000', $this->stockAt($product->id, $warehouse->id)->price_override);
    }

    public function test_dispatch_holds_source_stock_in_transit_not_in_reserved_quantity(): void
    {
        $tenantId = 'tenant-transfer-intransit-1';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = $this->makeLocation($tenantId, 'Warehouse', 'warehouse');
        $shop = $this->makeLocation($tenantId, 'Shop', 'shop');

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'In-Transit Widget',
            'item_type' => 'product', 'price' => 5, 'track_stock' => true, 'stock_quantity' => 20,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 20);

        $this->post('/office/transfers', [
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'items' => [['product_id' => $product->id, 'qty_requested' => 8]],
        ])->assertRedirect();
        $transfer = StockTransfer::where('business_id', $tenantId)->first();
        $item = $transfer->items->first();

        $this->post("/office/transfers/{$transfer->id}/dispatch", [
            'items' => [['item_id' => $item->id, 'qty_sent' => 8]],
        ])->assertRedirect();

        $sourceStock = $this->stockAt($product->id, $warehouse->id);
        // reserved_quantity is untouched — that field is order-holds only now.
        $this->assertSame('0.0000', $sourceStock->reserved_quantity);
        $this->assertSame('8.0000', $sourceStock->in_transit_quantity);
        // Available stock at source excludes what's been dispatched.
        $this->assertSame(12.0, (float) $sourceStock->available_quantity);
    }

    public function test_destination_sees_incoming_stock_before_the_transfer_is_received(): void
    {
        $tenantId = 'tenant-transfer-intransit-2';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = $this->makeLocation($tenantId, 'Warehouse', 'warehouse');
        $shop = $this->makeLocation($tenantId, 'Shop', 'shop');

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Incoming Widget',
            'item_type' => 'product', 'price' => 5, 'track_stock' => true, 'stock_quantity' => 20,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 20);

        $this->post('/office/transfers', [
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'items' => [['product_id' => $product->id, 'qty_requested' => 6]],
        ])->assertRedirect();
        $transfer = StockTransfer::where('business_id', $tenantId)->first();
        $item = $transfer->items->first();

        $this->post("/office/transfers/{$transfer->id}/dispatch", [
            'items' => [['item_id' => $item->id, 'qty_sent' => 6]],
        ])->assertRedirect();

        $destStock = $this->stockAt($product->id, $shop->id);
        $this->assertNotNull($destStock);
        // Nothing has physically arrived yet — quantity is still 0 — but the
        // branch can see 6 units are on their way.
        $this->assertSame('0.0000', $destStock->quantity);
        $this->assertSame('6.0000', $destStock->in_transit_quantity);
        $this->assertSame(6.0, (float) $destStock->expected_quantity);
    }

    public function test_receiving_a_transfer_reconciles_in_transit_quantity_to_zero_on_both_sides(): void
    {
        $tenantId = 'tenant-transfer-intransit-3';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = $this->makeLocation($tenantId, 'Warehouse', 'warehouse');
        $shop = $this->makeLocation($tenantId, 'Shop', 'shop');

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Reconciled Widget',
            'item_type' => 'product', 'price' => 5, 'track_stock' => true, 'stock_quantity' => 20,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 20);

        $this->post('/office/transfers', [
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'items' => [['product_id' => $product->id, 'qty_requested' => 7]],
        ])->assertRedirect();
        $transfer = StockTransfer::where('business_id', $tenantId)->first();
        $item = $transfer->items->first();

        $this->post("/office/transfers/{$transfer->id}/dispatch", [
            'items' => [['item_id' => $item->id, 'qty_sent' => 7]],
        ])->assertRedirect();

        $this->post("/office/transfers/{$transfer->id}/receive", [
            'items' => [['item_id' => $item->id, 'qty_received' => 7]],
        ])->assertRedirect();

        $this->assertSame('0.0000', $this->stockAt($product->id, $warehouse->id)->in_transit_quantity);
        $this->assertSame('0.0000', $this->stockAt($product->id, $shop->id)->in_transit_quantity);
        $this->assertSame('7.0000', $this->stockAt($product->id, $shop->id)->quantity);
    }

    public function test_in_transit_quantity_never_collides_with_an_unrelated_order_hold_reservation(): void
    {
        $tenantId = 'tenant-transfer-intransit-4';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = $this->makeLocation($tenantId, 'Warehouse', 'warehouse');
        $shop = $this->makeLocation($tenantId, 'Shop', 'shop');

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Dual-Hold Widget',
            'item_type' => 'product', 'price' => 5, 'track_stock' => true, 'stock_quantity' => 30,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 30);

        // An unrelated order-hold reservation on the same product/location.
        app(LocationService::class)->reserveStock($product->id, $warehouse->id, 5);

        $this->post('/office/transfers', [
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'items' => [['product_id' => $product->id, 'qty_requested' => 10]],
        ])->assertRedirect();
        $transfer = StockTransfer::where('business_id', $tenantId)->first();
        $item = $transfer->items->first();

        $this->post("/office/transfers/{$transfer->id}/dispatch", [
            'items' => [['item_id' => $item->id, 'qty_sent' => 10]],
        ])->assertRedirect();

        $sourceStock = $this->stockAt($product->id, $warehouse->id);
        $this->assertSame('5.0000', $sourceStock->reserved_quantity);
        $this->assertSame('10.0000', $sourceStock->in_transit_quantity);
        // 30 - 5 (order hold) - 10 (in transit) = 15.
        $this->assertSame(15.0, (float) $sourceStock->available_quantity);
    }

    public function test_dispatch_from_pending_is_blocked_once_the_business_requires_approval(): void
    {
        $tenantId = 'tenant-transfer-workflow-1';
        $this->actingBackOfficeSession($tenantId);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'workflow_settings' => ['stock_transfer_requires_approval' => true]]);

        $warehouse = $this->makeLocation($tenantId, 'Warehouse', 'warehouse');
        $shop = $this->makeLocation($tenantId, 'Shop', 'shop');
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Gated Widget',
            'item_type' => 'product', 'price' => 5, 'track_stock' => true, 'stock_quantity' => 10,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 10);

        $this->post('/office/transfers', [
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'items' => [['product_id' => $product->id, 'qty_requested' => 5]],
        ])->assertRedirect();
        $transfer = StockTransfer::where('business_id', $tenantId)->first();
        $item = $transfer->items->first();

        // Still pending — never approved — so dispatch must be refused.
        $this->post("/office/transfers/{$transfer->id}/dispatch", [
            'items' => [['item_id' => $item->id, 'qty_sent' => 5]],
        ])->assertSessionHasErrors('transfer');
        $this->assertSame('pending', $transfer->fresh()->status);

        // Approving first clears the gate.
        $this->post("/office/transfers/{$transfer->id}/approve")->assertRedirect();
        $this->post("/office/transfers/{$transfer->id}/dispatch", [
            'items' => [['item_id' => $item->id, 'qty_sent' => 5]],
        ])->assertRedirect();
        $this->assertSame('in_transit', $transfer->fresh()->status);
    }

    public function test_dispatch_from_pending_is_unaffected_when_the_workflow_toggle_is_off(): void
    {
        // Regression: absent/false workflow_settings must behave exactly like
        // no Business row at all — today's gate-free default.
        $tenantId = 'tenant-transfer-workflow-2';
        $this->actingBackOfficeSession($tenantId);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'workflow_settings' => ['stock_transfer_requires_approval' => false]]);

        $warehouse = $this->makeLocation($tenantId, 'Warehouse', 'warehouse');
        $shop = $this->makeLocation($tenantId, 'Shop', 'shop');
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Ungated Widget',
            'item_type' => 'product', 'price' => 5, 'track_stock' => true, 'stock_quantity' => 10,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 10);

        $this->post('/office/transfers', [
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'items' => [['product_id' => $product->id, 'qty_requested' => 5]],
        ])->assertRedirect();
        $transfer = StockTransfer::where('business_id', $tenantId)->first();
        $item = $transfer->items->first();

        $this->post("/office/transfers/{$transfer->id}/dispatch", [
            'items' => [['item_id' => $item->id, 'qty_sent' => 5]],
        ])->assertRedirect();
        $this->assertSame('in_transit', $transfer->fresh()->status);
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
