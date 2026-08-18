<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeStockTakesTest extends TestCase
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

    public function test_approving_a_pending_stock_take_adjusts_stock_and_closes_it(): void
    {
        $tenantId = 'tenant-stocktake-1';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop', 'type' => 'shop', 'is_active' => true]);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Counted Widget',
            'item_type' => 'product', 'price' => 5, 'track_stock' => true, 'stock_quantity' => 10,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $location->id, 10);

        $take = StockTake::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $location->id,
            'title' => 'Weekly count', 'status' => 'pending_approval', 'created_by_user_id' => (string) Str::uuid(),
        ]);
        $item = StockTakeItem::create([
            'id' => (string) Str::uuid(), 'stock_take_id' => $take->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'system_qty' => 10, 'counted_qty' => 7,
        ]);

        $this->post("/office/stocktakes/{$take->id}/approve", ['review_comment' => 'Looks right'])->assertRedirect();

        $take->refresh();
        $this->assertSame('approved', $take->status);
        $this->assertNotNull($take->approved_at);
        $this->assertSame('Looks right', $take->review_comment);

        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertSame('7.0000', $stock->quantity);
        $this->assertSame('7.0000', $product->fresh()->stock_quantity);

        $this->assertDatabaseHas('stock_movements', [
            'reference_id' => $take->id,
            'type' => 'stocktake',
            'product_id' => $product->id,
            'quantity_change' => -3,
        ]);
    }

    public function test_items_with_no_variance_write_no_movement(): void
    {
        $tenantId = 'tenant-stocktake-2';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop', 'type' => 'shop', 'is_active' => true]);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Exact Widget',
            'item_type' => 'product', 'price' => 5, 'track_stock' => true, 'stock_quantity' => 4,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $location->id, 4);

        $take = StockTake::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $location->id,
            'title' => 'Exact count', 'status' => 'pending_approval', 'created_by_user_id' => (string) Str::uuid(),
        ]);
        StockTakeItem::create([
            'id' => (string) Str::uuid(), 'stock_take_id' => $take->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'system_qty' => 4, 'counted_qty' => 4,
        ]);

        $this->post("/office/stocktakes/{$take->id}/approve")->assertRedirect();

        $this->assertSame('approved', $take->fresh()->status);
        $this->assertDatabaseMissing('stock_movements', ['reference_id' => $take->id]);
    }

    public function test_reject_changes_status_without_touching_stock(): void
    {
        $tenantId = 'tenant-stocktake-3';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop', 'type' => 'shop', 'is_active' => true]);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Rejected Widget',
            'item_type' => 'product', 'price' => 5, 'track_stock' => true, 'stock_quantity' => 5,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $location->id, 5);

        $take = StockTake::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $location->id,
            'title' => 'Suspicious count', 'status' => 'pending_approval', 'created_by_user_id' => (string) Str::uuid(),
        ]);
        StockTakeItem::create([
            'id' => (string) Str::uuid(), 'stock_take_id' => $take->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'system_qty' => 5, 'counted_qty' => 0,
        ]);

        $this->post("/office/stocktakes/{$take->id}/reject")->assertRedirect();

        $this->assertSame('rejected', $take->fresh()->status);
        $this->assertSame('5.0000', $product->fresh()->stock_quantity);
        $this->assertDatabaseMissing('stock_movements', ['reference_id' => $take->id]);
    }

    public function test_an_already_approved_stock_take_cannot_be_approved_again(): void
    {
        $tenantId = 'tenant-stocktake-4';
        $this->actingBackOfficeSession($tenantId);

        $take = StockTake::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'title' => 'Done already',
            'status' => 'approved', 'created_by_user_id' => (string) Str::uuid(),
        ]);

        $response = $this->post("/office/stocktakes/{$take->id}/approve");
        $response->assertSessionHasErrors('stock_take');
        $this->assertSame('approved', $take->fresh()->status);
    }

    public function test_cashier_cannot_review_stock_takes(): void
    {
        $tenantId = 'tenant-stocktake-5';
        $this->actingBackOfficeSession($tenantId, 'cashier');

        $take = StockTake::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'title' => 'Blocked',
            'status' => 'pending_approval', 'created_by_user_id' => (string) Str::uuid(),
        ]);

        $this->get('/office/stocktakes')->assertForbidden();
        $this->post("/office/stocktakes/{$take->id}/approve")->assertForbidden();
    }

    public function test_stock_takes_are_scoped_to_the_current_tenant(): void
    {
        $foreignTake = StockTake::create([
            'id' => (string) Str::uuid(), 'business_id' => 'tenant-stocktake-other', 'title' => 'Theirs',
            'status' => 'pending_approval', 'created_by_user_id' => (string) Str::uuid(),
        ]);

        $tenantId = 'tenant-stocktake-6';
        $this->actingBackOfficeSession($tenantId);

        $this->get("/office/stocktakes/{$foreignTake->id}")->assertNotFound();
        $this->post("/office/stocktakes/{$foreignTake->id}/approve")->assertNotFound();
        $this->assertSame('pending_approval', $foreignTake->fresh()->status);
    }
}
