<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Bundle;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeBundlesTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => '123456']);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(),
            'email' => 'office-bundles@example.com',
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

    private function createCatalogItem(string $tenantId, string $name, string $itemType): Product
    {
        return Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => $name,
            'item_type' => $itemType,
            'price' => $itemType === 'service' ? 30 : 120,
            'track_stock' => $itemType !== 'service',
            'stock_quantity' => $itemType === 'service' ? 0 : 5,
        ]);
    }

    public function test_combo_is_created_with_items_and_published_to_sync_stream(): void
    {
        $tenantId = 'tenant-bundles-1';
        $this->actingBackOfficeSession($tenantId);

        $carrier = $this->createCatalogItem($tenantId, 'Roof Carrier X200', 'product');
        $fitting = $this->createCatalogItem($tenantId, 'Carrier Fitting', 'service');

        $response = $this->post('/office/combos', [
            'name' => 'Carrier + Fitting',
            'items' => [
                ['product_id' => $carrier->id, 'quantity' => 1],
                ['product_id' => $fitting->id, 'quantity' => 1],
            ],
        ]);

        $response->assertRedirect();

        $bundle = Bundle::where('name', 'Carrier + Fitting')->with('items')->first();
        $this->assertNotNull($bundle);
        $this->assertCount(2, $bundle->items);
        $this->assertTrue($bundle->is_active);

        // The bundle and each item must be in the sync stream for all devices.
        $this->assertDatabaseHas('sync_records', [
            'table_name' => 'bundles',
            'record_uuid' => $bundle->id,
            'device_id' => null,
        ]);
        $this->assertSame(
            2,
            SyncRecord::where('table_name', 'bundle_items')
                ->where('operation', 'upsert')
                ->count()
        );
    }

    public function test_updating_a_combo_replaces_items_and_emits_deletes(): void
    {
        $tenantId = 'tenant-bundles-2';
        $this->actingBackOfficeSession($tenantId);

        $carrier = $this->createCatalogItem($tenantId, 'Roof Carrier X200', 'product');
        $fitting = $this->createCatalogItem($tenantId, 'Carrier Fitting', 'service');

        $this->post('/office/combos', [
            'name' => 'Carrier + Fitting',
            'items' => [['product_id' => $carrier->id, 'quantity' => 1]],
        ])->assertRedirect();

        $bundle = Bundle::where('name', 'Carrier + Fitting')->with('items')->first();
        $originalItemId = $bundle->items->first()->id;

        $this->put("/office/combos/{$bundle->id}", [
            'name' => 'Carrier + Fitting',
            'items' => [
                ['product_id' => $carrier->id, 'quantity' => 1],
                ['product_id' => $fitting->id, 'quantity' => 2],
            ],
        ])->assertRedirect();

        $bundle->refresh()->load('items');
        $this->assertCount(2, $bundle->items);

        // The replaced item must be gone locally and deleted on devices too.
        $this->assertDatabaseMissing('bundle_items', ['id' => $originalItemId]);
        $this->assertDatabaseHas('sync_records', [
            'table_name' => 'bundle_items',
            'record_uuid' => $originalItemId,
            'operation' => 'delete',
        ]);
    }

    public function test_combo_requires_at_least_one_item(): void
    {
        $tenantId = 'tenant-bundles-3';
        $this->actingBackOfficeSession($tenantId);

        $response = $this->post('/office/combos', [
            'name' => 'Empty Combo',
            'items' => [],
        ]);

        $response->assertSessionHasErrors('items');
        $this->assertDatabaseMissing('bundles', ['name' => 'Empty Combo']);
    }
}
