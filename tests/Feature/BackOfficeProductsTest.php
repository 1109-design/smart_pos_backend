<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeProductsTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => '123456']);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(),
            'email' => 'office-products@example.com',
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

    public function test_portal_created_product_gets_opening_stock_ledger_and_sync_record(): void
    {
        $tenantId = 'tenant-office-prod-1';
        $this->actingBackOfficeSession($tenantId);

        $response = $this->post('/office/products', [
            'name' => 'Portal Cola 500ml',
            'item_type' => 'product',
            'price' => 1.50,
            'cost_price' => 0.90,
            'stock_quantity' => 24,
            'track_stock' => true,
        ]);

        $response->assertRedirect();

        $product = Product::where('name', 'Portal Cola 500ml')->first();
        $this->assertNotNull($product);
        $this->assertSame('product', $product->item_type);

        // Opening stock must hit the ledger, not just the flat column.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'opening_stock',
            'quantity_change' => 24,
        ]);

        // And be published to the sync stream for every device (device_id null).
        $this->assertDatabaseHas('sync_records', [
            'table_name' => 'products',
            'record_uuid' => $product->id,
            'device_id' => null,
        ]);
    }

    public function test_portal_created_service_has_no_stock_or_ledger_entry(): void
    {
        $tenantId = 'tenant-office-prod-2';
        $this->actingBackOfficeSession($tenantId);

        $response = $this->post('/office/products', [
            'name' => 'Phone Screen Repair',
            'item_type' => 'service',
            'price' => 25,
            // Client-sent stock values must be ignored for services.
            'stock_quantity' => 99,
            'track_stock' => true,
        ]);

        $response->assertRedirect();

        $product = Product::where('name', 'Phone Screen Repair')->first();
        $this->assertNotNull($product);
        $this->assertSame('service', $product->item_type);
        $this->assertFalse((bool) $product->track_stock);
        $this->assertSame(0.0, (float) $product->stock_quantity);

        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $product->id,
        ]);
    }

    public function test_archiving_preserves_full_product_payload_in_sync_record(): void
    {
        $tenantId = 'tenant-office-prod-3';
        $this->actingBackOfficeSession($tenantId);

        $productId = (string) Str::uuid();
        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Keep My Name',
            'item_type' => 'product',
            'price' => 5,
            'stock_quantity' => 10,
        ]);

        $response = $this->patch("/office/products/{$productId}/toggle-active");

        $response->assertRedirect();

        // The product row keeps its identity and only flips is_active — the
        // sync payload must carry the full record so device pulls don't blank
        // out name/price.
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => 'Keep My Name',
            'is_active' => false,
        ]);

        $syncRecord = SyncRecord::where('record_uuid', $productId)->latest('id')->first();
        $this->assertNotNull($syncRecord);
        $this->assertSame('Keep My Name', $syncRecord->payload['name']);
        $this->assertFalse((bool) $syncRecord->payload['is_active']);
    }
}
