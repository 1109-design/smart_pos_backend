<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Product;
use App\Exceptions\BarcodeConflictException;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\SyncRecord;
use App\Services\BarcodeRegistry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncProductBarcodesTest extends TestCase
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

    private function pushBarcode(string $token, string $id, string $productId, string $operation = 'upsert')
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'product_barcodes',
                    'uuid' => $id,
                    'operation' => $operation,
                    'payload' => $operation === 'upsert'
                        ? ['product_id' => $productId, 'barcode' => '6001234567890']
                        : [],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    private function makeProduct(string $tenantId): Product
    {
        return Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 1, 'track_stock' => true,
        ]);
    }

    public function test_additional_barcode_can_be_pushed_through_the_generic_sync_endpoint(): void
    {
        $tenantId = 'tenant-sync-barcode-1';
        $token = $this->actingDeviceToken($tenantId);
        $product = $this->makeProduct($tenantId);

        $id = (string) Str::uuid();
        $response = $this->pushBarcode($token, $id, $product->id);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');
        $this->assertDatabaseHas('product_barcodes', [
            'id' => $id,
            'product_id' => $product->id,
            'barcode' => '6001234567890',
        ]);
        $this->assertSame(['6001234567890'], $product->barcodes()->pluck('barcode')->all());
    }

    public function test_a_device_cannot_attach_a_barcode_to_another_tenants_product(): void
    {
        $victimTenant = 'tenant-sync-barcode-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $victimProduct = $this->makeProduct($victimTenant);

        $attackerToken = $this->actingDeviceToken('tenant-sync-barcode-attacker');
        $response = $this->pushBarcode($attackerToken, (string) Str::uuid(), $victimProduct->id);

        $response->assertOk();
        $response->assertJsonCount(0, 'accepted');
        $response->assertJsonCount(1, 'errors');
        $this->assertDatabaseMissing('product_barcodes', ['product_id' => $victimProduct->id]);
    }

    public function test_deleting_an_additional_barcode_removes_it(): void
    {
        $tenantId = 'tenant-sync-barcode-delete';
        $token = $this->actingDeviceToken($tenantId);
        $product = $this->makeProduct($tenantId);
        $barcode = ProductBarcode::create([
            'id' => (string) Str::uuid(), 'product_id' => $product->id, 'barcode' => '6001234567890',
        ]);

        $this->pushBarcode($token, $barcode->id, $product->id, 'delete')->assertOk();

        $this->assertDatabaseMissing('product_barcodes', ['id' => $barcode->id]);
    }

    private function push(string $token, array $records)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => array_map(fn ($r) => [...$r, 'updated_at' => now()->toIso8601String()], $records),
            ]);
    }

    private function productRecord(string $id, ?string $barcode, string $name = 'Widget'): array
    {
        return [
            'table' => 'products',
            'uuid' => $id,
            'operation' => 'upsert',
            'payload' => ['name' => $name, 'item_type' => 'product', 'price' => 1, 'track_stock' => true, 'barcode' => $barcode],
        ];
    }

    public function test_database_rejects_a_barcode_already_used_anywhere_in_the_business(): void
    {
        Tenant::create(['id' => 'tenant-bc-db', 'business_name' => 'x', 'owner_email' => 'bc-db@example.com']);
        $a = $this->makeProduct('tenant-bc-db');
        $a->update(['barcode' => 'X1']);
        ProductBarcode::create(['id' => (string) Str::uuid(), 'product_id' => $a->id, 'barcode' => 'X2']);
        ProductVariant::create(['id' => (string) Str::uuid(), 'product_id' => $a->id, 'name' => 'Red', 'barcode' => 'X3']);
        $b = $this->makeProduct('tenant-bc-db');

        foreach (['X1', 'X2', 'X3'] as $code) {
            try {
                ProductBarcode::create(['id' => (string) Str::uuid(), 'product_id' => $b->id, 'barcode' => $code]);
                $this->fail("{$code} should have been rejected");
            } catch (BarcodeConflictException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(BarcodeConflictException::class);
        $b->update(['barcode' => 'X2']);
    }

    public function test_same_barcode_is_fine_in_another_business_and_after_release(): void
    {
        $a = $this->makeProduct('tenant-bc-a');
        $a->update(['barcode' => 'SAME']);
        $other = $this->makeProduct('tenant-bc-b');
        $other->update(['barcode' => 'SAME']);

        $a->update(['barcode' => null]);
        $b = $this->makeProduct('tenant-bc-a');
        $b->update(['barcode' => 'SAME']);

        $this->assertSame('SAME', $b->fresh()->barcode);
        // Unrelated saves never re-check an existing barcode.
        $b->update(['stock_quantity' => 5]);
    }

    public function test_pushed_product_with_a_taken_barcode_is_accepted_with_the_server_value_kept(): void
    {
        $tenantId = 'tenant-bc-push';
        $token = $this->actingDeviceToken($tenantId);
        $owner = $this->makeProduct($tenantId);
        $owner->update(['barcode' => 'TAKEN']);

        $existing = $this->makeProduct($tenantId);
        $existing->update(['barcode' => 'MINE']);

        $newId = (string) Str::uuid();
        $response = $this->push($token, [
            $this->productRecord($newId, 'TAKEN', 'New thing'),
            $this->productRecord($existing->id, 'TAKEN', 'Renamed'),
        ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'accepted');
        $this->assertNull(Product::find($newId)->barcode);
        $this->assertSame('New thing', Product::find($newId)->name);
        $this->assertSame('MINE', $existing->fresh()->barcode);
        $this->assertSame('Renamed', $existing->fresh()->name);
        $this->assertSame('TAKEN', $owner->fresh()->barcode);

        // The stored changefeed row — what every device pulls, the pusher
        // included — carries the resolved barcode, never the conflicting one.
        $this->assertNull(SyncRecord::where('record_uuid', $newId)->latest('id')->first()->payload['barcode']);
        $this->assertSame('MINE', SyncRecord::where('record_uuid', $existing->id)->latest('id')->first()->payload['barcode']);
        $this->assertDatabaseHas('sync_conflicts', [
            'record_uuid' => $newId,
            'conflict_type' => 'barcode_conflict',
            'status' => 'resolved',
        ]);
    }

    public function test_pushed_additional_barcode_that_is_taken_becomes_a_delete(): void
    {
        $tenantId = 'tenant-bc-extra';
        $token = $this->actingDeviceToken($tenantId);
        $owner = $this->makeProduct($tenantId);
        $owner->update(['barcode' => 'TAKEN']);
        $product = $this->makeProduct($tenantId);

        $id = (string) Str::uuid();
        $response = $this->push($token, [[
            'table' => 'product_barcodes',
            'uuid' => $id,
            'operation' => 'upsert',
            'payload' => ['product_id' => $product->id, 'barcode' => 'TAKEN'],
        ]]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');
        $this->assertDatabaseMissing('product_barcodes', ['id' => $id]);
        $this->assertSame('delete', SyncRecord::where('record_uuid', $id)->latest('id')->first()->operation);
    }

    public function test_a_barcode_can_move_between_products_in_one_push(): void
    {
        $tenantId = 'tenant-bc-move';
        $token = $this->actingDeviceToken($tenantId);
        $a = $this->makeProduct($tenantId);
        $a->update(['barcode' => 'MOVE']);
        $b = $this->makeProduct($tenantId);
        $extra = ProductBarcode::create(['id' => (string) Str::uuid(), 'product_id' => $b->id, 'barcode' => 'EXTRA']);

        $response = $this->push($token, [
            $this->productRecord($a->id, null),
            $this->productRecord($b->id, 'MOVE'),
            ['table' => 'product_barcodes', 'uuid' => $extra->id, 'operation' => 'delete', 'payload' => []],
            ['table' => 'product_barcodes', 'uuid' => (string) Str::uuid(), 'operation' => 'upsert', 'payload' => ['product_id' => $a->id, 'barcode' => 'EXTRA']],
        ]);

        $response->assertOk();
        $response->assertJsonCount(4, 'accepted');
        $this->assertSame('MOVE', $b->fresh()->barcode);
        $this->assertSame(['EXTRA'], $a->barcodes()->pluck('barcode')->all());
        $this->assertDatabaseCount('sync_conflicts', 0);
    }

    public function test_rebuild_keeps_the_oldest_holder_of_a_legacy_duplicate(): void
    {
        $tenantId = 'tenant-bc-legacy';
        $old = Product::withoutEvents(fn () => Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Old', 'item_type' => 'product',
            'price' => 1, 'track_stock' => true, 'barcode' => 'DUP',
        ]));
        Product::whereKey($old->id)->update(['created_at' => now()->subDays(2)]);
        $new = Product::withoutEvents(fn () => Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'New', 'item_type' => 'product',
            'price' => 1, 'track_stock' => true, 'barcode' => 'DUP', 'is_taxable' => false,
        ]));

        $cleared = app(BarcodeRegistry::class)->rebuild();

        $this->assertSame(1, $cleared);
        $this->assertSame('DUP', $old->fresh()->barcode);
        $this->assertNull($new->fresh()->barcode);
        $this->assertSame(['type' => 'product', 'id' => $old->id], app(BarcodeRegistry::class)->ownerOf($tenantId, 'DUP'));

        // Devices get a full row (not a partial that would wipe other
        // columns), loser first, then the winner re-sent after it.
        $records = SyncRecord::orderBy('id')->get();
        $this->assertSame([$new->id, $old->id], $records->pluck('record_uuid')->all());
        $this->assertNull($records[0]->payload['barcode']);
        $this->assertFalse($records[0]->payload['is_taxable']);
        $this->assertSame('New', $records[0]->payload['name']);
        $this->assertSame('DUP', $records[1]->payload['barcode']);
    }

    public function test_a_claim_left_by_a_raw_delete_does_not_block_the_barcode(): void
    {
        $a = $this->makeProduct('tenant-bc-stale');
        $row = ProductBarcode::create(['id' => (string) Str::uuid(), 'product_id' => $a->id, 'barcode' => 'STALE']);
        ProductBarcode::whereKey($row->id)->delete(); // no model events

        $this->assertNull(app(BarcodeRegistry::class)->ownerOf('tenant-bc-stale', 'STALE'));
        $b = $this->makeProduct('tenant-bc-stale');
        $b->update(['barcode' => 'STALE']);
        $this->assertSame('STALE', $b->fresh()->barcode);
    }
}
