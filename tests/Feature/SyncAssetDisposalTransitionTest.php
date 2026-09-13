<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Two-till concurrency audit follow-up: an asset's disposal can be recorded
 * from any device (see Asset::isValidTransition()'s doc comment), but had no
 * domain-aware transition guard, so a device that disposed an asset and
 * stayed offline could resync its own stale 'active' snapshot afterwards,
 * silently resurrecting a disposed asset — which the monthly depreciation
 * sweep would then pick back up and keep depreciating. Mirrors
 * SyncStockTransferTransitionTest / SyncBankReconciliationTransitionTest.
 */
class SyncAssetDisposalTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => '88888888-8888-4888-8888-888888888888',
            'email' => 'sync-asset-owner@example.com',
        ]);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function pushAsset(string $token, string $tenantId, string $assetId, string $status): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'assets',
                    'uuid' => $assetId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'asset_number' => 'FA-000001',
                        'name' => 'Delivery Van',
                        'acquisition_date' => now()->subYear()->toDateString(),
                        'acquisition_cost' => 20000,
                        'salvage_value' => 2000,
                        'useful_life_months' => 60,
                        'funding_method' => 'cash',
                        'status' => $status,
                        'disposed_at' => $status === 'disposed' ? now()->toDateString() : null,
                        'created_by_user_id' => '88888888-8888-4888-8888-888888888888',
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_disposing_directly_from_active_is_allowed(): void
    {
        $tenantId = 'tenant-asset-1';
        $token = $this->actingDeviceToken($tenantId);
        $assetId = (string) Str::uuid();

        $this->pushAsset($token, $tenantId, $assetId, 'active')->assertOk();
        $this->pushAsset($token, $tenantId, $assetId, 'disposed')->assertOk();

        $this->assertDatabaseHas('assets', [
            'id' => $assetId,
            'status' => 'disposed',
        ]);
    }

    public function test_a_disposed_asset_cannot_be_resurrected(): void
    {
        $tenantId = 'tenant-asset-2';
        $token = $this->actingDeviceToken($tenantId);
        $assetId = (string) Str::uuid();

        $this->pushAsset($token, $tenantId, $assetId, 'active')->assertOk();
        $this->pushAsset($token, $tenantId, $assetId, 'disposed')->assertOk();

        // Simulates the exact race the audit flagged: a device that stayed
        // offline since creating the asset, replaying its stale 'active'
        // snapshot after another device already disposed of it.
        $response = $this->pushAsset($token, $tenantId, $assetId, 'active');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('assets', [
            'id' => $assetId,
            'status' => 'disposed',
        ]);
    }

    public function test_resyncing_the_same_status_is_a_safe_no_op(): void
    {
        $tenantId = 'tenant-asset-3';
        $token = $this->actingDeviceToken($tenantId);
        $assetId = (string) Str::uuid();

        $this->pushAsset($token, $tenantId, $assetId, 'active')->assertOk();
        $response = $this->pushAsset($token, $tenantId, $assetId, 'active');

        $response->assertOk();
        $response->assertJsonCount(0, 'errors');

        $this->assertDatabaseHas('assets', [
            'id' => $assetId,
            'status' => 'active',
        ]);
    }
}
