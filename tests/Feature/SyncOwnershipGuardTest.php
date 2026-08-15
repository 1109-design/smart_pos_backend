<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\BundleItem;
use App\Models\Category;
use App\Models\Device;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves a device authenticated for one business cannot create, overwrite, or
 * delete another business's record by guessing/knowing its UUID — the gap
 * found in the 2026-08-15 sync audit (SyncProcessor::handleUpsert/handleDelete
 * keyed purely on `id`, with no ownership check of their own).
 */
class SyncOwnershipGuardTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create(['email' => $tenantId.'-owner@example.com']);

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

    public function test_push_cannot_overwrite_another_businesss_product(): void
    {
        $victimTenant = 'tenant-guard-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $victimProductId = (string) Str::uuid();
        Product::create(['id' => $victimProductId, 'business_id' => $victimTenant, 'name' => 'Victim Product', 'item_type' => 'product', 'price' => 10]);

        $attackerToken = $this->actingDeviceToken('tenant-guard-attacker');

        $response = $this->withHeader('Authorization', 'Bearer '.$attackerToken)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'products',
                    'uuid' => $victimProductId,
                    'operation' => 'upsert',
                    'payload' => ['name' => 'Hijacked Product', 'item_type' => 'product', 'price' => 1],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'accepted');
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('products', [
            'id' => $victimProductId,
            'business_id' => $victimTenant,
            'name' => 'Victim Product',
        ]);
    }

    public function test_push_cannot_delete_another_businesss_category(): void
    {
        $victimTenant = 'tenant-guard-victim-2';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $victimCategoryId = (string) Str::uuid();
        Category::create(['id' => $victimCategoryId, 'business_id' => $victimTenant, 'name' => 'Victim Category', 'is_active' => true]);

        $attackerToken = $this->actingDeviceToken('tenant-guard-attacker-2');

        $response = $this->withHeader('Authorization', 'Bearer '.$attackerToken)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'categories',
                    'uuid' => $victimCategoryId,
                    'operation' => 'delete',
                    'payload' => [],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'accepted');
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('categories', ['id' => $victimCategoryId, 'is_active' => true]);
    }

    public function test_same_tenant_upsert_and_delete_still_work(): void
    {
        $tenantId = 'tenant-guard-owner';
        $token = $this->actingDeviceToken($tenantId);
        $productId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $tenantId, 'name' => 'My Product', 'item_type' => 'product', 'price' => 5]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'products',
                    'uuid' => $productId,
                    'operation' => 'upsert',
                    'payload' => ['business_id' => $tenantId, 'name' => 'Renamed', 'item_type' => 'product', 'price' => 6],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');
        $this->assertDatabaseHas('products', ['id' => $productId, 'name' => 'Renamed']);
    }

    public function test_processor_rejects_upsert_with_no_business_id_for_new_record(): void
    {
        $this->expectException(\RuntimeException::class);

        app(SyncProcessor::class)->process('products', (string) Str::uuid(), 'upsert', [
            'name' => 'Orphan Product', 'item_type' => 'product', 'price' => 1,
        ]);
    }

    public function test_businesses_table_can_only_sync_its_own_record(): void
    {
        $this->expectException(\RuntimeException::class);

        app(SyncProcessor::class)->process('businesses', 'someone-elses-tenant-id', 'upsert', [
            'business_id' => 'my-tenant-id', 'name' => 'Attempted Rename',
        ]);
    }

    public function test_users_cannot_be_overwritten_by_another_business_via_device_push(): void
    {
        // The gap this covers: User's Eloquent global scope only applies when
        // tenancy() is initialized, which never happens on the device sync API
        // path — so without this explicit check, syncUser() had zero tenant
        // protection here even though the back-office web path is safe.
        $victimTenant = 'tenant-guard-users-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $victimUser = User::factory()->create(['business_id' => $victimTenant, 'name' => 'Victim User', 'email' => 'victim-user@example.com']);

        $attackerToken = $this->actingDeviceToken('tenant-guard-users-attacker');

        $response = $this->withHeader('Authorization', 'Bearer '.$attackerToken)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'users',
                    'uuid' => $victimUser->id,
                    'operation' => 'upsert',
                    'payload' => ['name' => 'Hijacked', 'role' => 'admin', 'is_active' => true],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'accepted');
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('users', ['id' => $victimUser->id, 'name' => 'Victim User']);
    }

    public function test_transaction_item_cannot_be_attached_to_another_businesss_transaction(): void
    {
        $victimTenant = 'tenant-guard-tx-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $victimTxId = (string) Str::uuid();
        $victimUser = User::factory()->create(['business_id' => $victimTenant, 'email' => 'tx-owner@example.com']);
        Transaction::create([
            'id' => $victimTxId, 'business_id' => $victimTenant, 'user_id' => $victimUser->id,
            'subtotal' => 10, 'total' => 10, 'tax_total' => 0, 'base_currency' => 'USD', 'status' => 'completed',
        ]);

        $this->expectException(\RuntimeException::class);

        app(SyncProcessor::class)->process('transaction_items', (string) Str::uuid(), 'upsert', [
            'business_id' => 'tenant-guard-tx-attacker',
            'transaction_id' => $victimTxId,
            'product_name' => 'Free Stuff',
            'quantity' => 1,
            'unit_price' => 0,
            'line_total' => 0,
        ]);
    }

    public function test_bundle_item_cannot_be_hijacked_into_another_businesss_bundle(): void
    {
        $victimTenant = 'tenant-guard-bundle-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $attackerTenant = 'tenant-guard-bundle-attacker';
        Tenant::create(['id' => $attackerTenant, 'business_name' => $attackerTenant, 'owner_email' => $attackerTenant.'@example.com']);

        $victimBundleId = (string) Str::uuid();
        Bundle::create(['id' => $victimBundleId, 'business_id' => $victimTenant, 'name' => 'Victim Combo', 'is_active' => true]);
        $existingItemId = (string) Str::uuid();
        BundleItem::create(['id' => $existingItemId, 'bundle_id' => $victimBundleId, 'product_id' => (string) Str::uuid(), 'quantity' => 1]);

        $attackerBundleId = (string) Str::uuid();
        Bundle::create(['id' => $attackerBundleId, 'business_id' => $attackerTenant, 'name' => 'Attacker Combo', 'is_active' => true]);

        $this->expectException(\RuntimeException::class);

        // Attacker tries to re-parent the victim's existing bundle item into their own bundle.
        app(SyncProcessor::class)->process('bundle_items', $existingItemId, 'upsert', [
            'business_id' => $attackerTenant,
            'bundle_id' => $attackerBundleId,
            'product_id' => (string) Str::uuid(),
            'quantity' => 99,
        ]);
    }
}
