<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeStoremanTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId, string $role = 'business_owner'): User
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
                'role' => $role,
                'business_name' => $tenantId,
                'currency_code' => 'USD',
            ],
        ]);

        return $user;
    }

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

    private function makeProduct(string $tenantId, string $name, float $threshold = 5): Product
    {
        return Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => $name,
            'item_type' => 'product',
            'price' => 5,
            'track_stock' => true,
            'is_active' => true,
            'low_stock_threshold' => $threshold,
            'stock_quantity' => 0,
        ]);
    }

    public function test_stock_health_counts_low_and_surplus_correctly(): void
    {
        $tenantId = 'tenant-storeman-1';
        $this->actingBackOfficeSession($tenantId);

        $shop = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop', 'type' => 'shop', 'is_active' => true]);

        $low = $this->makeProduct($tenantId, 'Low Widget', 10);
        $this->seedLocationStock($tenantId, $low->id, $shop->id, 3); // <= threshold

        $surplus = $this->makeProduct($tenantId, 'Surplus Widget', 10);
        $this->seedLocationStock($tenantId, $surplus->id, $shop->id, 30); // > 2x threshold

        $healthy = $this->makeProduct($tenantId, 'Healthy Widget', 10);
        $this->seedLocationStock($tenantId, $healthy->id, $shop->id, 15); // between threshold and 2x

        $response = $this->get('/office/storeman');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stock_health.0.id', $shop->id)
            ->where('stock_health.0.low_stock_count', 1)
            ->where('stock_health.0.surplus_count', 1)
            ->where('stock_health.0.sku_count', 3)
        );
    }

    public function test_suggests_a_transfer_when_a_shop_is_low_and_its_warehouse_has_surplus(): void
    {
        $tenantId = 'tenant-storeman-2';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $shop = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop', 'type' => 'shop', 'parent_id' => $warehouse->id, 'is_active' => true]);

        $product = $this->makeProduct($tenantId, 'Restockable Widget', 10);
        $this->seedLocationStock($tenantId, $product->id, $shop->id, 2);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 50);

        $response = $this->get('/office/storeman');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('suggestions', 1)
            ->where('suggestions.0.product_id', $product->id)
            ->where('suggestions.0.from_location_id', $warehouse->id)
            ->where('suggestions.0.to_location_id', $shop->id)
            ->where('suggestions.0.suggested_qty', 8) // shortfall = 10 - 2; whole numbers serialize as JSON ints
        );
    }

    public function test_no_suggestion_when_warehouse_has_no_surplus(): void
    {
        $tenantId = 'tenant-storeman-3';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $shop = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop', 'type' => 'shop', 'parent_id' => $warehouse->id, 'is_active' => true]);

        $product = $this->makeProduct($tenantId, 'Also Low Everywhere', 10);
        $this->seedLocationStock($tenantId, $product->id, $shop->id, 2);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 5); // also below threshold

        $response = $this->get('/office/storeman');
        $response->assertInertia(fn ($page) => $page->has('suggestions', 0));
    }

    public function test_pending_actions_lists_awaiting_approval_and_flags_stuck_in_transit(): void
    {
        $tenantId = 'tenant-storeman-4';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $shop = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop', 'type' => 'shop', 'is_active' => true]);

        StockTransfer::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'transfer_number' => 'TRF-A',
            'from_location_id' => $warehouse->id, 'to_location_id' => $shop->id,
            'status' => 'pending', 'requested_by_user_id' => (string) Str::uuid(),
        ]);
        StockTransfer::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'transfer_number' => 'TRF-B',
            'from_location_id' => $warehouse->id, 'to_location_id' => $shop->id,
            'status' => 'in_transit', 'requested_by_user_id' => (string) Str::uuid(),
            'dispatched_at' => now()->subDays(5),
        ]);
        StockTransfer::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'transfer_number' => 'TRF-C',
            'from_location_id' => $warehouse->id, 'to_location_id' => $shop->id,
            'status' => 'in_transit', 'requested_by_user_id' => (string) Str::uuid(),
            'dispatched_at' => now()->subHours(2),
        ]);

        $response = $this->get('/office/storeman');
        $response->assertInertia(fn ($page) => $page
            ->has('pending_actions.awaiting_approval', 1)
            ->has('pending_actions.awaiting_receipt', 2)
            ->where('pending_actions.awaiting_receipt.0.transfer_number', 'TRF-B')
            ->where('pending_actions.awaiting_receipt.0.stuck', true)
            ->where('pending_actions.awaiting_receipt.1.transfer_number', 'TRF-C')
            ->where('pending_actions.awaiting_receipt.1.stuck', false)
        );
    }

    public function test_accepting_a_suggestion_creates_a_real_pending_transfer(): void
    {
        $tenantId = 'tenant-storeman-5';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $shop = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop', 'type' => 'shop', 'parent_id' => $warehouse->id, 'is_active' => true]);
        $product = $this->makeProduct($tenantId, 'Suggested Widget', 10);

        $this->post('/office/storeman/suggested-transfer', [
            'product_id' => $product->id,
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'qty' => 8,
        ])->assertRedirect();

        $transfer = StockTransfer::where('business_id', $tenantId)->first();
        $this->assertNotNull($transfer);
        $this->assertSame('pending', $transfer->status);
        $this->assertSame($warehouse->id, $transfer->from_location_id);
        $this->assertSame($shop->id, $transfer->to_location_id);
        $item = $transfer->items->first();
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame('8.0000', $item->qty_requested);
    }

    public function test_cashier_cannot_view_storeman_or_request_transfers(): void
    {
        $tenantId = 'tenant-storeman-6';
        $this->actingBackOfficeSession($tenantId, 'cashier');

        $this->get('/office/storeman')->assertForbidden();
        $this->post('/office/storeman/suggested-transfer', [])->assertForbidden();
    }

    public function test_suggested_transfer_cannot_use_another_tenants_location(): void
    {
        $otherTenantId = 'tenant-storeman-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => 'QQQQQQ']);
        $foreignWarehouse = Location::create(['id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Their Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $foreignProduct = $this->makeProduct($otherTenantId, 'Their Widget');

        $tenantId = 'tenant-storeman-7';
        $this->actingBackOfficeSession($tenantId);
        $shop = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop', 'type' => 'shop', 'is_active' => true]);

        $this->post('/office/storeman/suggested-transfer', [
            'product_id' => $foreignProduct->id,
            'from_location_id' => $foreignWarehouse->id,
            'to_location_id' => $shop->id,
            'qty' => 5,
        ])->assertNotFound();

        $this->assertDatabaseMissing('stock_transfers', ['business_id' => $tenantId]);
    }
}
