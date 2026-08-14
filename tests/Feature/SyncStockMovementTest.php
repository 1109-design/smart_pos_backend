<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Product;
use App\Models\StockMovement;
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
}
