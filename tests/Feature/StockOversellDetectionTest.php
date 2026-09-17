<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0 fix: two tills selling more of a product than is actually in stock,
 * both offline, must not have the shortfall silently absorbed by
 * SyncProcessor::recomputeProductStock/recomputeLocationStock's max(0, ...)
 * clamp. The clamp still protects every OTHER device from ever seeing or
 * transacting against a negative quantity, but the oversell itself must now
 * be flagged in stock_oversells for manager review instead of vanishing.
 */
class StockOversellDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(string $tenantId, string $name): string
    {
        $user = User::factory()->create(['email' => $tenantId.'-'.Str::slug($name).'@example.com']);
        $plain = $user->createToken($name)->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_two_tills_overselling_the_same_product_creates_a_stock_oversell_record(): void
    {
        $tenantId = 'tenant-oversell-1';
        Tenant::create(['id' => $tenantId, 'business_name' => 'Oversell Test Biz', 'owner_email' => $tenantId.'@example.com']);

        $tillA = $this->makeDevice($tenantId, 'Till A');
        $tillB = $this->makeDevice($tenantId, 'Till B');

        $productId = (string) Str::uuid();
        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Widget',
            'item_type' => 'product',
            'price' => 5,
            'track_stock' => true,
            'stock_quantity' => 10,
        ]);

        $location = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Main Shop',
            'type' => 'shop',
        ]);

        StockMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => 10,
            'user_id' => (string) Str::uuid(),
        ]);

        // Till A sells 6 offline, Till B sells 7 offline — both push after
        // reconnecting. Combined demand (13) exceeds available stock (10).
        $this->withHeader('Authorization', 'Bearer '.$tillA)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => $location->id,
                        'product_id' => $productId,
                        'type' => 'sale',
                        'quantity_change' => -6,
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $pushB = $this->withHeader('Authorization', 'Bearer '.$tillB)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => $location->id,
                        'product_id' => $productId,
                        'type' => 'sale',
                        'quantity_change' => -7,
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
        $pushB->assertOk();
        $pushB->assertJsonCount(1, 'accepted');

        // The shortfall must be flagged, not silently dropped.
        $this->assertDatabaseHas('stock_oversells', [
            'business_id' => $tenantId,
            'product_id' => $productId,
            'location_id' => $location->id,
            'computed_quantity' => -3.0,
            'shortfall' => 3.0,
        ]);
        $this->assertDatabaseCount('stock_oversells', 1);

        // But every other device must still see a clamped, non-negative
        // total — never a negative number they could act on.
        $pull = $this->withHeader('Authorization', 'Bearer '.$tillA)
            ->getJson('/api/v1/sync/pull');
        $pull->assertOk();

        // Both Till A's own push and Till B's push each triggered a recompute
        // broadcast for this product — take the LAST (most recent) one, not
        // the first, since the changelog is append-only and both land in
        // this single pull.
        $productRecord = collect($pull->json('records'))
            ->filter(fn ($r) => $r['table_name'] === 'products' && $r['record_uuid'] === $productId)
            ->last();
        $this->assertNotNull($productRecord);
        $this->assertEquals(0.0, $productRecord['payload']['stock_quantity']);

        $stockRecord = collect($pull->json('records'))
            ->filter(fn ($r) => $r['table_name'] === 'product_stock'
                && $r['payload']['product_id'] === $productId
                && $r['payload']['location_id'] === $location->id)
            ->last();
        $this->assertNotNull($stockRecord);
        $this->assertEquals(0.0, $stockRecord['payload']['quantity']);
    }

    public function test_a_second_oversell_on_the_same_still_unresolved_shortfall_updates_the_existing_row(): void
    {
        $tenantId = 'tenant-oversell-2';
        Tenant::create(['id' => $tenantId, 'business_name' => 'Oversell Test Biz 2', 'owner_email' => $tenantId.'@example.com']);
        $till = $this->makeDevice($tenantId, 'Till A');

        $productId = (string) Str::uuid();
        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Gadget',
            'item_type' => 'product',
            'price' => 5,
            'track_stock' => true,
            'stock_quantity' => 5,
        ]);

        StockMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => 5,
            'user_id' => (string) Str::uuid(),
        ]);

        // First oversell: -8 total against 5 in stock (shortfall 3).
        $this->withHeader('Authorization', 'Bearer '.$till)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'stock_movements',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'product_id' => $productId,
                    'type' => 'sale',
                    'quantity_change' => -8,
                    'user_id' => (string) Str::uuid(),
                ],
                'updated_at' => now()->toIso8601String(),
            ]]])->assertOk();

        // Worsens without anyone resolving it yet: another -2.
        $this->withHeader('Authorization', 'Bearer '.$till)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'stock_movements',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'product_id' => $productId,
                    'type' => 'sale',
                    'quantity_change' => -2,
                    'user_id' => (string) Str::uuid(),
                ],
                'updated_at' => now()->toIso8601String(),
            ]]])->assertOk();

        $this->assertDatabaseCount('stock_oversells', 1);
        $this->assertDatabaseHas('stock_oversells', [
            'business_id' => $tenantId,
            'product_id' => $productId,
            'computed_quantity' => -5.0,
            'shortfall' => 5.0,
            'resolved_at' => null,
        ]);
    }

    public function test_normal_recompute_does_not_create_an_oversell_record(): void
    {
        $tenantId = 'tenant-oversell-3';
        Tenant::create(['id' => $tenantId, 'business_name' => 'Oversell Test Biz 3', 'owner_email' => $tenantId.'@example.com']);
        $till = $this->makeDevice($tenantId, 'Till A');

        $productId = (string) Str::uuid();
        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Doohickey',
            'item_type' => 'product',
            'price' => 5,
            'track_stock' => true,
            'stock_quantity' => 10,
        ]);

        StockMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => 10,
            'user_id' => (string) Str::uuid(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$till)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'stock_movements',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'product_id' => $productId,
                    'type' => 'sale',
                    'quantity_change' => -4,
                    'user_id' => (string) Str::uuid(),
                ],
                'updated_at' => now()->toIso8601String(),
            ]]])->assertOk();

        $this->assertDatabaseCount('stock_oversells', 0);
    }

    public function test_oversells_endpoint_is_scoped_to_the_authenticated_devices_business(): void
    {
        $ownTenant = 'tenant-oversell-own';
        $otherTenant = 'tenant-oversell-other';
        Tenant::create(['id' => $ownTenant, 'business_name' => 'Own Biz', 'owner_email' => $ownTenant.'@example.com']);
        Tenant::create(['id' => $otherTenant, 'business_name' => 'Other Biz', 'owner_email' => $otherTenant.'@example.com']);

        $ownToken = $this->makeDevice($ownTenant, 'Own Till');
        $otherToken = $this->makeDevice($otherTenant, 'Other Till');

        // Shortfall-of-2 for own tenant, shortfall-of-4 for the other — kept
        // distinct so the assertion below can't pass by coincidence.
        foreach ([$ownTenant => [3, 2], $otherTenant => [6, 4]] as $tenantId => [$openingStock, $overBy]) {
            $productId = (string) Str::uuid();
            Product::create([
                'id' => $productId,
                'business_id' => $tenantId,
                'name' => 'Product for '.$tenantId,
                'item_type' => 'product',
                'price' => 5,
                'track_stock' => true,
                'stock_quantity' => $openingStock,
            ]);
            StockMovement::create([
                'id' => (string) Str::uuid(),
                'business_id' => $tenantId,
                'product_id' => $productId,
                'type' => 'opening_stock',
                'quantity_change' => $openingStock,
                'user_id' => (string) Str::uuid(),
            ]);

            $token = $tenantId === $ownTenant ? $ownToken : $otherToken;
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/api/v1/sync/push', ['records' => [[
                    'table' => 'stock_movements',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'product_id' => $productId,
                        'type' => 'sale',
                        'quantity_change' => -($openingStock + $overBy),
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]]])->assertOk();
        }

        $this->assertDatabaseCount('stock_oversells', 2);

        $response = $this->withHeader('Authorization', 'Bearer '.$ownToken)
            ->getJson('/api/v1/sync/oversells');
        $response->assertOk();

        $oversells = $response->json('oversells');
        $this->assertCount(1, $oversells);
        $this->assertEquals($ownTenant, $oversells[0]['business_id']);
        $this->assertEquals(-2.0, (float) $oversells[0]['computed_quantity']);
    }
}
