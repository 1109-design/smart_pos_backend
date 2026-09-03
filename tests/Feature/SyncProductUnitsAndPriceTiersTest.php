<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncProductUnitsAndPriceTiersTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_product_unit_can_be_pushed_through_the_generic_sync_endpoint(): void
    {
        $tenantId = 'tenant-sync-unit-1';
        $token = $this->actingDeviceToken($tenantId);

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 1, 'track_stock' => true,
        ]);

        $unitId = (string) Str::uuid();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'product_units',
                    'uuid' => $unitId,
                    'operation' => 'upsert',
                    'payload' => [
                        'product_id' => $product->id,
                        'unit_name' => 'box',
                        'conversion_factor' => 100,
                        'is_base_unit' => false,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');
        $this->assertDatabaseHas('product_units', [
            'id' => $unitId,
            'product_id' => $product->id,
            'unit_name' => 'box',
            'conversion_factor' => 100,
        ]);
    }

    public function test_product_price_tier_can_be_pushed_through_the_generic_sync_endpoint(): void
    {
        $tenantId = 'tenant-sync-tier-1';
        $token = $this->actingDeviceToken($tenantId);

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 10, 'track_stock' => true,
        ]);

        $tierId = (string) Str::uuid();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'product_price_tiers',
                    'uuid' => $tierId,
                    'operation' => 'upsert',
                    'payload' => [
                        'product_id' => $product->id,
                        'min_qty' => 10,
                        'unit_price' => 8,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');
        $this->assertDatabaseHas('product_price_tiers', [
            'id' => $tierId,
            'product_id' => $product->id,
            'min_qty' => 10,
            'unit_price' => 8,
        ]);
    }

    public function test_a_device_cannot_attach_a_unit_or_tier_to_another_tenants_product(): void
    {
        $victimTenant = 'tenant-sync-unit-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $victimProduct = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $victimTenant, 'name' => 'Their Widget',
            'item_type' => 'product', 'price' => 1, 'track_stock' => true,
        ]);

        $attackerToken = $this->actingDeviceToken('tenant-sync-unit-attacker');

        $response = $this->withHeader('Authorization', 'Bearer '.$attackerToken)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'product_units',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => 'tenant-sync-unit-attacker',
                        'product_id' => $victimProduct->id,
                        'unit_name' => 'box',
                        'conversion_factor' => 100,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'accepted');
        $response->assertJsonCount(1, 'errors');
        $this->assertDatabaseMissing('product_units', ['product_id' => $victimProduct->id]);
    }

    public function test_deleting_a_price_tier_removes_it(): void
    {
        $tenantId = 'tenant-sync-tier-delete';
        $token = $this->actingDeviceToken($tenantId);

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 10, 'track_stock' => true,
        ]);
        $tier = ProductPriceTier::create([
            'id' => (string) Str::uuid(), 'product_id' => $product->id, 'min_qty' => 10, 'unit_price' => 8,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'product_price_tiers',
                    'uuid' => $tier->id,
                    'operation' => 'delete',
                    'payload' => [],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $this->assertDatabaseMissing('product_price_tiers', ['id' => $tier->id]);
    }
}
