<?php

namespace Tests\Feature;

use App\Models\ContainerDepositLedger;
use App\Models\Device;
use App\Models\Product;
use App\Models\ProductContainerLink;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the returnable-packaging (container deposit) sync path: containers
 * are Products with item_type 'container', linked to sellable products via
 * product_container_links, with the money/liability side tracked in the
 * append-only container_deposit_ledger.
 */
class SyncReturnablePackagingTest extends TestCase
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

    public function test_product_container_link_and_deposit_issue_sync_via_push(): void
    {
        $tenantId = 'tenant-returnables-1';
        $token = $this->actingDeviceToken($tenantId);

        $beverageId = (string) Str::uuid();
        $containerId = (string) Str::uuid();
        $linkId = (string) Str::uuid();
        $ledgerId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        Product::create(['id' => $beverageId, 'business_id' => $tenantId, 'name' => 'Chibuku Quart - Crate of 12', 'item_type' => 'product', 'price' => 12]);
        Product::create(['id' => $containerId, 'business_id' => $tenantId, 'name' => 'Quart Bottle', 'item_type' => 'container', 'price' => 0, 'deposit_amount' => 0.20]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [
                    [
                        'table' => 'product_container_links',
                        'uuid' => $linkId,
                        'operation' => 'upsert',
                        'payload' => [
                            'beverage_product_id' => $beverageId,
                            'container_product_id' => $containerId,
                            'quantity_per_unit' => 12,
                        ],
                        'updated_at' => now()->toIso8601String(),
                    ],
                    [
                        'table' => 'container_deposit_ledger',
                        'uuid' => $ledgerId,
                        'operation' => 'upsert',
                        'payload' => [
                            'business_id' => $tenantId,
                            'container_product_id' => $containerId,
                            'quantity' => 12,
                            'deposit_amount_per_unit' => 0.20,
                            'type' => 'issue',
                            'user_id' => $userId,
                        ],
                        'updated_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'accepted');

        $this->assertDatabaseHas('product_container_links', [
            'id' => $linkId,
            'beverage_product_id' => $beverageId,
            'container_product_id' => $containerId,
            'quantity_per_unit' => 12,
        ]);

        $this->assertDatabaseHas('container_deposit_ledger', [
            'id' => $ledgerId,
            'business_id' => $tenantId,
            'container_product_id' => $containerId,
            'quantity' => 12,
            'type' => 'issue',
        ]);
    }

    public function test_container_deposit_ledger_cannot_be_overwritten_by_another_business(): void
    {
        $victimTenant = 'tenant-returnables-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $containerId = (string) Str::uuid();
        Product::create(['id' => $containerId, 'business_id' => $victimTenant, 'name' => 'Quart Bottle', 'item_type' => 'container', 'price' => 0]);

        $ledgerId = (string) Str::uuid();
        ContainerDepositLedger::create([
            'id' => $ledgerId,
            'business_id' => $victimTenant,
            'container_product_id' => $containerId,
            'quantity' => 12,
            'deposit_amount_per_unit' => 0.20,
            'type' => 'issue',
            'user_id' => (string) Str::uuid(),
        ]);

        $attackerToken = $this->actingDeviceToken('tenant-returnables-attacker');

        $response = $this->withHeader('Authorization', 'Bearer '.$attackerToken)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'container_deposit_ledger',
                    'uuid' => $ledgerId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => 'tenant-returnables-attacker',
                        'container_product_id' => $containerId,
                        'quantity' => 999,
                        'deposit_amount_per_unit' => 0.20,
                        'type' => 'forfeit',
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'accepted');
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('container_deposit_ledger', [
            'id' => $ledgerId,
            'business_id' => $victimTenant,
            'quantity' => 12,
            'type' => 'issue',
        ]);
    }

    public function test_product_container_link_cannot_be_hijacked_into_another_businesss_product(): void
    {
        $victimTenant = 'tenant-returnables-link-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $attackerTenant = 'tenant-returnables-link-attacker';
        Tenant::create(['id' => $attackerTenant, 'business_name' => $attackerTenant, 'owner_email' => $attackerTenant.'@example.com']);

        $victimBeverageId = (string) Str::uuid();
        Product::create(['id' => $victimBeverageId, 'business_id' => $victimTenant, 'name' => 'Victim Beverage', 'item_type' => 'product', 'price' => 5]);
        $existingLinkId = (string) Str::uuid();
        ProductContainerLink::create(['id' => $existingLinkId, 'beverage_product_id' => $victimBeverageId, 'container_product_id' => (string) Str::uuid(), 'quantity_per_unit' => 1]);

        $attackerContainerId = (string) Str::uuid();
        Product::create(['id' => $attackerContainerId, 'business_id' => $attackerTenant, 'name' => 'Attacker Container', 'item_type' => 'container', 'price' => 0]);

        $this->expectException(\RuntimeException::class);

        // Attacker tries to re-parent the victim's existing link onto a product they own.
        app(SyncProcessor::class)->process('product_container_links', $existingLinkId, 'upsert', [
            'beverage_product_id' => $attackerContainerId,
            'container_product_id' => (string) Str::uuid(),
            'quantity_per_unit' => 1,
        ]);
    }

    public function test_container_deposit_ledger_rows_are_immutable(): void
    {
        $tenantId = 'tenant-returnables-immutable';
        $containerId = (string) Str::uuid();
        Product::create(['id' => $containerId, 'business_id' => $tenantId, 'name' => 'Quart Bottle', 'item_type' => 'container', 'price' => 0]);

        $ledgerId = (string) Str::uuid();
        ContainerDepositLedger::create([
            'id' => $ledgerId,
            'business_id' => $tenantId,
            'container_product_id' => $containerId,
            'quantity' => 5,
            'deposit_amount_per_unit' => 0.20,
            'type' => 'issue',
            'user_id' => (string) Str::uuid(),
        ]);

        app(SyncProcessor::class)->process('container_deposit_ledger', $ledgerId, 'delete', ['business_id' => $tenantId]);

        $this->assertDatabaseHas('container_deposit_ledger', ['id' => $ledgerId]);
    }
}
