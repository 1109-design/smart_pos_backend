<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One stock row per product and location: when a till pushes the pair under
 * another id, the server keeps one row and tells every till to drop the id it
 * replaced, so none is left holding two rows for one product (2026-11-24:
 * "Bad state: Too many elements" on a goods receipt approval).
 */
class SyncProductStockIdTakeoverTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-stock-takeover';

    private function till(): string
    {
        $user = User::factory()->create(['business_id' => $this->tenantId, 'email' => Str::random(8).'@example.com']);
        $plain = $user->createToken('till')->plainTextToken;
        Device::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function pushStock(string $token, string $id, string $productId, string $locationId): void
    {
        $at = now()->toIso8601String();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'product_stock',
                'uuid' => $id,
                'operation' => 'upsert',
                'payload' => ['product_id' => $productId, 'location_id' => $locationId, 'quantity' => 5, 'updated_at' => $at],
                'updated_at' => $at,
            ]]])
            ->assertOk();
    }

    /** @return list<array<string, mixed>> */
    private function pulled(string $token): array
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull?tables[]=product_stock')
            ->assertOk()
            ->json('records');
    }

    public function test_a_push_under_a_new_id_tells_every_till_to_drop_the_old_one(): void
    {
        Tenant::create(['id' => $this->tenantId, 'business_name' => 'Stock Co', 'owner_email' => 'stock@example.com']);
        $productId = (string) Str::uuid();
        $locationId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $this->tenantId, 'name' => 'Bucket', 'price' => 5]);
        $oldId = (string) Str::uuid();
        ProductStock::create(['id' => $oldId, 'product_id' => $productId, 'location_id' => $locationId, 'quantity' => 5]);

        $pusher = $this->till();
        $other = $this->till();
        $canonical = "{$productId}|{$locationId}";

        $this->pushStock($pusher, $canonical, $productId, $locationId);

        $this->assertSame([$canonical], ProductStock::where('product_id', $productId)->pluck('id')->all());
        $drop = collect($this->pulled($other))
            ->firstWhere(fn ($r) => $r['record_uuid'] === $oldId);
        $this->assertNotNull($drop, 'the other till is told to drop the replaced id');
        $this->assertSame('delete', $drop['operation']);
        $this->assertSame('product_stock', $drop['table_name']);

        // The pushing till holds the old id too, so it gets the delete as well.
        $this->assertContains($oldId, collect($this->pulled($pusher))->pluck('record_uuid')->all());
    }

    public function test_a_push_under_the_same_id_sends_no_delete(): void
    {
        Tenant::create(['id' => $this->tenantId, 'business_name' => 'Stock Co', 'owner_email' => 'stock@example.com']);
        $productId = (string) Str::uuid();
        $locationId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $this->tenantId, 'name' => 'Bucket', 'price' => 5]);
        $canonical = "{$productId}|{$locationId}";
        ProductStock::create(['id' => $canonical, 'product_id' => $productId, 'location_id' => $locationId, 'quantity' => 5]);
        $pusher = $this->till();
        $other = $this->till();

        $this->pushStock($pusher, $canonical, $productId, $locationId);

        $this->assertSame([], collect($this->pulled($other))->where('operation', 'delete')->values()->all());
    }
}
