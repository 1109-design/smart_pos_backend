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
 * Multi-location stock consistency: when one device (e.g. a till at Shop A)
 * pushes a stock movement, every *other* device on the same business (a
 * neighbouring shop, a warehouse, or a reporting device) must learn the
 * server's recomputed authoritative quantity on its next pull — not just
 * replay the pushing device's own (possibly stale, under concurrency) number.
 *
 * Regression: SyncProcessor::recomputeProductStock/recomputeLocationStock
 * corrected the database but never emitted a SyncRecord, so the fix never
 * reached any device other than the one that happened to push last.
 */
class StockRecomputeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-stock-broadcast';

    private function makeDevice(string $name): string
    {
        $user = User::factory()->create();
        $plain = $user->createToken($name)->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $this->tenantId,
            'name' => $name,
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::create([
            'id' => $this->tenantId,
            'business_name' => 'Broadcast Test Biz',
            'owner_email' => $this->tenantId.'@example.com',
        ]);
    }

    public function test_a_sibling_device_learns_the_recomputed_stock_after_another_device_sells(): void
    {
        $tillA = $this->makeDevice('Till A');
        $tillB = $this->makeDevice('Till B');

        $productId = (string) Str::uuid();
        Product::create([
            'id' => $productId,
            'business_id' => $this->tenantId,
            'name' => 'Coca-Cola 500ml',
            'item_type' => 'product',
            'price' => 1.5,
            'track_stock' => true,
            'stock_quantity' => 20,
        ]);

        $location = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->tenantId,
            'name' => 'Main Shop',
            'type' => 'shop',
        ]);

        // Opening stock ledger entry — without this the movement-sum recompute
        // has nothing to add the sale's -3 on top of (mirrors what a real
        // product creation via sync push does automatically).
        StockMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->tenantId,
            'location_id' => $location->id,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => 20,
            'user_id' => (string) Str::uuid(),
        ]);

        // Till A sells 3 units — pushes the stock_movement it always would.
        $movementId = (string) Str::uuid();
        $push = $this->withHeader('Authorization', 'Bearer '.$tillA)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => $movementId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $this->tenantId,
                        'location_id' => $location->id,
                        'product_id' => $productId,
                        'type' => 'sale',
                        'quantity_change' => -3,
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
        $push->assertOk();
        $push->assertJsonCount(1, 'accepted');

        // Till B — a different device that never touched this product —
        // pulls next. It must see the corrected totals, not just silence.
        $pull = $this->withHeader('Authorization', 'Bearer '.$tillB)
            ->getJson('/api/v1/sync/pull');
        $pull->assertOk();

        $records = collect($pull->json('records'));

        $productRecord = $records->first(fn ($r) => $r['table_name'] === 'products' && $r['record_uuid'] === $productId);
        $this->assertNotNull($productRecord, 'Till B never received the recomputed product total.');
        $this->assertEquals(17.0, $productRecord['payload']['stock_quantity']);
        // Full snapshot, not a partial payload that would wipe other fields.
        $this->assertEquals('Coca-Cola 500ml', $productRecord['payload']['name']);

        $stockRecord = $records->first(fn ($r) => $r['table_name'] === 'product_stock'
            && $r['payload']['product_id'] === $productId
            && $r['payload']['location_id'] === $location->id);
        $this->assertNotNull($stockRecord, 'Till B never received the recomputed per-location stock.');
        $this->assertEquals(17.0, $stockRecord['payload']['quantity']);
    }

    public function test_broadcast_record_is_not_echoed_back_to_the_originating_device(): void
    {
        $tillA = $this->makeDevice('Till A');

        $productId = (string) Str::uuid();
        Product::create([
            'id' => $productId,
            'business_id' => $this->tenantId,
            'name' => 'Fanta 500ml',
            'item_type' => 'product',
            'price' => 1.5,
            'track_stock' => true,
            'stock_quantity' => 10,
        ]);

        StockMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->tenantId,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => 10,
            'user_id' => (string) Str::uuid(),
        ]);

        $movementId = (string) Str::uuid();
        $this->withHeader('Authorization', 'Bearer '.$tillA)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => $movementId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $this->tenantId,
                        'product_id' => $productId,
                        'type' => 'sale',
                        'quantity_change' => -1,
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        // The device-agnostic broadcast (device_id null) is still visible to
        // the originating device on its own next pull — self-healing in case
        // its own locally-computed number ever drifted.
        $pull = $this->withHeader('Authorization', 'Bearer '.$tillA)
            ->getJson('/api/v1/sync/pull');
        $pull->assertOk();

        $productRecord = collect($pull->json('records'))
            ->first(fn ($r) => $r['table_name'] === 'products' && $r['record_uuid'] === $productId);

        $this->assertNotNull($productRecord);
        $this->assertEquals(9.0, $productRecord['payload']['stock_quantity']);
    }

    /**
     * Regression: productSyncPayload() omitted every sheet_* column, so the
     * recompute broadcast nulled a sheet's dimensions on every device that
     * pulled it — which then pushed the nulls back on its next product save.
     */
    public function test_recompute_broadcast_carries_sheet_dimensions(): void
    {
        $tillA = $this->makeDevice('Till A');
        $tillB = $this->makeDevice('Till B');

        $productId = (string) Str::uuid();
        Product::create([
            'id' => $productId,
            'business_id' => $this->tenantId,
            'name' => 'Glass 4mm Clear 2440x1830',
            'item_type' => 'sheet',
            'unit' => 'm²',
            'price' => 85,
            'track_stock' => true,
            'stock_quantity' => 10,
            'sheet_width' => 2.44,
            'sheet_height' => 1.83,
            'sheet_kerf_width' => 0.003,
            'sheet_allow_rotate' => false,
            'is_taxable' => false,
        ]);
        StockMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->tenantId,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => 10,
            'user_id' => (string) Str::uuid(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$tillA)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $this->tenantId,
                        'product_id' => $productId,
                        'type' => 'sale',
                        'quantity_change' => -1,
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $payload = collect($this->withHeader('Authorization', 'Bearer '.$tillB)
            ->getJson('/api/v1/sync/pull')->json('records'))
            ->first(fn ($r) => $r['table_name'] === 'products' && $r['record_uuid'] === $productId)['payload'];

        $this->assertEquals(2.44, $payload['sheet_width']);
        $this->assertEquals(1.83, $payload['sheet_height']);
        $this->assertEquals(0.003, $payload['sheet_kerf_width']);
        $this->assertFalse($payload['sheet_allow_rotate']);
        $this->assertFalse($payload['is_taxable']);
    }

    /**
     * Older app builds push product snapshots without sheet_* keys at all
     * (stock recompute, archive). An absent key must keep the stored value;
     * an explicit null still clears it.
     */
    public function test_product_push_without_sheet_keys_keeps_stored_dimensions(): void
    {
        $tillA = $this->makeDevice('Till A');

        $productId = (string) Str::uuid();
        Product::create([
            'id' => $productId,
            'business_id' => $this->tenantId,
            'name' => 'Glass 3mm Clear 1830x1220',
            'item_type' => 'sheet',
            'price' => 24,
            'sheet_width' => 1.83,
            'sheet_height' => 1.22,
        ]);

        $push = fn (array $extra) => $this->withHeader('Authorization', 'Bearer '.$tillA)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'products',
                    'uuid' => $productId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $this->tenantId,
                        'name' => 'Glass 3mm Clear 1830x1220',
                        'item_type' => 'sheet',
                        'price' => 25,
                        'is_active' => true,
                    ] + $extra,
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $push([]);
        $product = Product::find($productId);
        $this->assertEquals(25.0, (float) $product->price);
        $this->assertEquals(1.83, (float) $product->sheet_width);
        $this->assertEquals(1.22, (float) $product->sheet_height);

        $push(['sheet_width' => null, 'sheet_height' => null]);
        $product->refresh();
        $this->assertNull($product->sheet_width);
        $this->assertNull($product->sheet_height);
    }
}
