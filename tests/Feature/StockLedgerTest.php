<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId]);

        $user = User::factory()->create([
            'email' => "device-owner-{$tenantId}@example.com",
        ]);

        $plain = $user->createToken('ledger-test')->plainTextToken;
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

    private function createMovement(string $businessId, array $overrides = []): StockMovement
    {
        return StockMovement::create(array_merge([
            'id' => \Str::uuid(),
            'business_id' => $businessId,
            'product_id' => \Str::uuid(),
            'type' => 'sale',
            'quantity_change' => -1,
            'user_id' => \Str::uuid(),
        ], $overrides));
    }

    public function test_api_index_returns_200_for_authenticated_device(): void
    {
        $tenantId = 'tenant-ledger-001';
        $token = $this->actingDeviceToken($tenantId);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/stock-ledger');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_api_index_returns_401_for_unauthenticated_request(): void
    {
        $response = $this->getJson('/api/v1/stock-ledger');

        $response->assertStatus(401);
    }

    public function test_api_filters_by_type(): void
    {
        $tenantId = 'tenant-ledger-002';
        $token = $this->actingDeviceToken($tenantId);

        $productId = (string) \Str::uuid();
        $userId = (string) \Str::uuid();

        $this->createMovement($tenantId, ['type' => 'sale', 'product_id' => $productId, 'user_id' => $userId]);
        $this->createMovement($tenantId, ['type' => 'receive', 'product_id' => $productId, 'user_id' => $userId]);
        $this->createMovement($tenantId, ['type' => 'adjustment', 'product_id' => $productId, 'user_id' => $userId]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/stock-ledger?type=sale');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $movement) {
            $this->assertEquals('sale', $movement['type']);
        }
    }

    public function test_api_filters_by_product_id(): void
    {
        $tenantId = 'tenant-ledger-003';
        $token = $this->actingDeviceToken($tenantId);

        $productA = (string) \Str::uuid();
        $productB = (string) \Str::uuid();
        $userId = (string) \Str::uuid();

        $this->createMovement($tenantId, ['product_id' => $productA, 'user_id' => $userId]);
        $this->createMovement($tenantId, ['product_id' => $productA, 'user_id' => $userId]);
        $this->createMovement($tenantId, ['product_id' => $productB, 'user_id' => $userId]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/stock-ledger?product_id='.$productA);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $movement) {
            $this->assertEquals($productA, $movement['product_id']);
        }
    }

    public function test_api_filters_by_date_range(): void
    {
        $tenantId = 'tenant-ledger-004';
        $token = $this->actingDeviceToken($tenantId);

        $productId = (string) \Str::uuid();
        $userId = (string) \Str::uuid();

        StockMovement::create([
            'id' => (string) \Str::uuid(),
            'business_id' => $tenantId,
            'product_id' => $productId,
            'type' => 'sale',
            'quantity_change' => -1,
            'user_id' => $userId,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        StockMovement::create([
            'id' => (string) \Str::uuid(),
            'business_id' => $tenantId,
            'product_id' => $productId,
            'type' => 'receive',
            'quantity_change' => 5,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $from = now()->subDays(1)->toDateString();
        $to = now()->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/stock-ledger?from={$from}&to={$to}");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('receive', $data[0]['type']);
    }
}
