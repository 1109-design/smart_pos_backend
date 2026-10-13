<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncStockMovementTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => '99999999-9999-4999-9999-999999999999',
            'email' => 'sync-stock-owner@example.com',
        ]);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_location_tagged_sale_updates_both_the_location_ledger_and_the_flat_total(): void
    {
        $tenantId = 'tenant-sync-stock-1';
        $token = $this->actingDeviceToken($tenantId);

        $productId = (string) Str::uuid();
        $locationId = (string) Str::uuid();
        $movementId = (string) Str::uuid();

        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Test Product',
            'price' => 10,
            'stock_quantity' => 20,
        ]);

        // Ledger-driven stock is recomputed purely from stock_movements, so the
        // opening balance for this location needs its own movement — matching
        // how a real device would have recorded take-on stock at this location.
        StockMovement::create([
            'business_id' => $tenantId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => 20,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => $movementId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => $locationId,
                        'product_id' => $productId,
                        'type' => 'sale',
                        'quantity_change' => -5,
                        'user_id' => '99999999-9999-4999-9999-999999999999',
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();

        // The per-location ledger reflects the sale.
        $this->assertDatabaseHas('product_stock', [
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity' => 15,
        ]);

        // The legacy flat total must not be left stale — it's the field the
        // inventory dashboard reads, so it has to agree with the ledger above.
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'stock_quantity' => 15,
        ]);
    }

    public function test_product_stock_quantity_never_goes_negative_from_overselling(): void
    {
        // recomputeLocationStock already clamped its own ProductStock row to
        // max(0, ...) — this is the flat products.stock_quantity total, which
        // didn't, so an oversold product (e.g. two concurrent sales pushed
        // from different devices before either saw the other's movement)
        // would leave every device's inventory screen showing a negative
        // count for it once the recomputed total broadcast back down.
        $tenantId = 'tenant-sync-stock-4';
        $token = $this->actingDeviceToken($tenantId);

        $productId = (string) Str::uuid();

        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Oversold Product',
            'price' => 10,
            'stock_quantity' => 5,
        ]);

        StockMovement::create([
            'business_id' => $tenantId,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => 5,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'product_id' => $productId,
                        'type' => 'sale',
                        'quantity_change' => -8,
                        'user_id' => '99999999-9999-4999-9999-999999999999',
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'stock_quantity' => 0,
        ]);
    }

    public function test_new_product_with_opening_stock_gets_a_ledger_entry(): void
    {
        $tenantId = 'tenant-sync-stock-2';
        $token = $this->actingDeviceToken($tenantId);

        $productId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'products',
                    'uuid' => $productId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'name' => 'Take-On Product',
                        'price' => 25,
                        'stock_quantity' => 40,
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => 40,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'stock_quantity' => 40,
        ]);
    }

    public function test_server_created_opening_stock_movement_is_broadcast_to_devices(): void
    {
        $tenantId = 'tenant-sync-stock-4';
        $token = $this->actingDeviceToken($tenantId);

        $productId = (string) Str::uuid();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'products',
                    'uuid' => $productId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'name' => 'Take-On Product',
                        'price' => 25,
                        'cost_price' => 12,
                        'stock_quantity' => 40,
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])
            ->assertOk();

        $movement = StockMovement::where('product_id', $productId)->where('type', 'opening_stock')->firstOrFail();

        $record = SyncRecord::where('table_name', 'stock_movements')
            ->where('record_uuid', $movement->id)
            ->first();

        $this->assertNotNull($record, 'The opening_stock movement must be published so tills can pull it.');
        $this->assertNull($record->device_id);
        $this->assertSame($tenantId, $record->business_id);
        $this->assertSame('opening_stock', $record->payload['type']);
        $this->assertEquals(40, $record->payload['quantity_change']);
        $this->assertEquals(12, $record->payload['unit_cost']);
        $this->assertSame($productId, $record->payload['product_id']);
        $this->assertNotNull($record->payload['created_at']);
    }

    public function test_updating_an_existing_product_does_not_duplicate_opening_stock(): void
    {
        $tenantId = 'tenant-sync-stock-3';
        $token = $this->actingDeviceToken($tenantId);

        $productId = (string) Str::uuid();

        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Existing Product',
            'price' => 25,
            'stock_quantity' => 40,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'products',
                    'uuid' => $productId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'name' => 'Existing Product Renamed',
                        'price' => 25,
                        'stock_quantity' => 40,
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();

        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function pushNewProduct(string $token, string $tenantId, string $productId, array $extra = []): void
    {
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'products',
                    'uuid' => $productId,
                    'operation' => 'upsert',
                    'payload' => $extra + [
                        'business_id' => $tenantId,
                        'name' => 'Take-On Product',
                        'price' => 25,
                        'cost_price' => 12,
                        'stock_quantity' => 40,
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])
            ->assertOk();
    }

    private function makeLocation(string $tenantId, string $name): Location
    {
        return Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => $name, 'type' => 'shop', 'is_active' => true]);
    }

    public function test_opening_stock_uses_the_location_the_till_sends(): void
    {
        $tenantId = 'tenant-sync-stock-5';
        $token = $this->actingDeviceToken($tenantId);
        $this->makeLocation($tenantId, 'Branch A');
        $branchB = $this->makeLocation($tenantId, 'Branch B');
        $productId = (string) Str::uuid();

        $this->pushNewProduct($token, $tenantId, $productId, ['location_id' => $branchB->id]);

        $this->assertDatabaseHas('stock_movements', ['product_id' => $productId, 'type' => 'opening_stock', 'location_id' => $branchB->id]);
        $this->assertDatabaseHas('product_stock', ['product_id' => $productId, 'location_id' => $branchB->id, 'quantity' => 40]);
    }

    public function test_opening_stock_without_a_location_falls_back_to_the_sole_location(): void
    {
        $tenantId = 'tenant-sync-stock-6';
        $token = $this->actingDeviceToken($tenantId);
        $main = $this->makeLocation($tenantId, 'Main');
        $productId = (string) Str::uuid();

        $this->pushNewProduct($token, $tenantId, $productId);

        $this->assertDatabaseHas('stock_movements', ['product_id' => $productId, 'type' => 'opening_stock', 'location_id' => $main->id]);
        $this->assertDatabaseHas('product_stock', ['product_id' => $productId, 'location_id' => $main->id, 'quantity' => 40]);
    }

    public function test_opening_stock_without_a_location_stays_untagged_when_there_are_several(): void
    {
        $tenantId = 'tenant-sync-stock-7';
        $token = $this->actingDeviceToken($tenantId);
        $this->makeLocation($tenantId, 'Branch A');
        $this->makeLocation($tenantId, 'Branch B');
        $productId = (string) Str::uuid();

        $this->pushNewProduct($token, $tenantId, $productId);

        $this->assertDatabaseHas('stock_movements', ['product_id' => $productId, 'type' => 'opening_stock', 'location_id' => null]);
    }

    public function test_backfill_publishes_unpublished_opening_stock_and_fills_in_an_unambiguous_location(): void
    {
        $tenantId = 'tenant-sync-stock-8';
        $branchA = $this->makeLocation($tenantId, 'Branch A');
        $branchB = $this->makeLocation($tenantId, 'Branch B');

        $located = StockMovement::create(['business_id' => $tenantId, 'location_id' => $branchA->id, 'product_id' => (string) Str::uuid(), 'type' => 'opening_stock', 'quantity_change' => 10, 'unit_cost' => 2]);

        // No location, but the product only has stock at Branch B.
        $productId = (string) Str::uuid();
        ProductStock::create(['id' => $productId.'|'.$branchB->id, 'product_id' => $productId, 'location_id' => $branchB->id, 'quantity' => 5]);
        $untagged = StockMovement::create(['business_id' => $tenantId, 'location_id' => null, 'product_id' => $productId, 'type' => 'opening_stock', 'quantity_change' => 5, 'unit_cost' => 1]);

        // No location and no way to tell which branch — published as-is.
        $ambiguous = StockMovement::create(['business_id' => $tenantId, 'location_id' => null, 'product_id' => (string) Str::uuid(), 'type' => 'opening_stock', 'quantity_change' => 3, 'unit_cost' => 1]);

        // Not opening stock — left alone.
        $sale = StockMovement::create(['business_id' => $tenantId, 'location_id' => $branchA->id, 'product_id' => (string) Str::uuid(), 'type' => 'sale', 'quantity_change' => -1]);

        $this->artisan('stock:publish-opening-movements', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, SyncRecord::where('table_name', 'stock_movements')->count());
        $this->assertNull($untagged->fresh()->location_id);

        $this->artisan('stock:publish-opening-movements')->assertSuccessful();

        $this->assertSame($branchB->id, $untagged->fresh()->location_id);
        $this->assertNull($ambiguous->fresh()->location_id);

        foreach ([$located, $untagged, $ambiguous] as $movement) {
            $this->assertSame(1, SyncRecord::where('table_name', 'stock_movements')->where('record_uuid', $movement->id)->count());
        }
        $this->assertSame($branchB->id, SyncRecord::where('record_uuid', $untagged->id)->first()->payload['location_id']);
        $this->assertSame(0, SyncRecord::where('record_uuid', $sale->id)->count());

        // Re-running publishes nothing twice.
        $this->artisan('stock:publish-opening-movements')->assertSuccessful();
        $this->assertSame(3, SyncRecord::where('table_name', 'stock_movements')->count());
    }

    public function test_backfill_does_not_tag_a_location_whose_ledger_already_covers_the_stock(): void
    {
        $tenantId = 'tenant-sync-stock-9';
        $main = $this->makeLocation($tenantId, 'Main');
        $productId = (string) Str::uuid();

        // Real case: an untagged 48 opening line, plus the same 48 keyed in
        // again at the location as a manual count, then a sale of 2 — the
        // location's own ledger (48 - 2) already matches its stock of 46.
        ProductStock::create(['id' => $productId.'|'.$main->id, 'product_id' => $productId, 'location_id' => $main->id, 'quantity' => 46]);
        $opening = StockMovement::create(['business_id' => $tenantId, 'location_id' => null, 'product_id' => $productId, 'type' => 'opening_stock', 'quantity_change' => 48, 'unit_cost' => 1]);
        StockMovement::create(['business_id' => $tenantId, 'location_id' => $main->id, 'product_id' => $productId, 'type' => 'adjustment', 'quantity_change' => 48]);
        StockMovement::create(['business_id' => $tenantId, 'location_id' => $main->id, 'product_id' => $productId, 'type' => 'sale', 'quantity_change' => -2]);

        $this->artisan('stock:publish-opening-movements')->assertSuccessful();

        $this->assertNull($opening->fresh()->location_id);
        $this->assertSame(1, SyncRecord::where('record_uuid', $opening->id)->count());
    }

    public function test_untagged_adjustment_is_attributed_to_the_sole_location(): void
    {
        $tenantId = 'tenant-sync-stock-10';
        $token = $this->actingDeviceToken($tenantId);
        $main = $this->makeLocation($tenantId, 'Main');
        $productId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $tenantId, 'name' => 'Barbed Wire', 'price' => 10, 'stock_quantity' => 0]);
        $movementId = (string) Str::uuid();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => $movementId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'product_id' => $productId,
                        'type' => 'adjustment',
                        'quantity_change' => 32,
                        'reason' => 'Physical Stock Count',
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('stock_movements', ['id' => $movementId, 'location_id' => $main->id]);
        $this->assertDatabaseHas('product_stock', ['product_id' => $productId, 'location_id' => $main->id, 'quantity' => 32]);
        $this->assertDatabaseHas('products', ['id' => $productId, 'stock_quantity' => 32]);

        $republished = SyncRecord::where('table_name', 'stock_movements')->where('record_uuid', $movementId)->whereNull('device_id')->first();
        $this->assertNotNull($republished);
        $this->assertSame($main->id, $republished->payload['location_id']);
    }
}
