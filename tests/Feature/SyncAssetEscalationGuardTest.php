<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Security audit follow-up, same class as the salary/supplier payment
 * escalation guards: an asset acquisition posts a real Dr Fixed Assets /
 * Cr Cash-or-Bank entry for an item that was never actually bought, and a
 * disposal does the same for proceeds that were never actually received.
 * The till only shows the Assets screen at all to UserRole.owner
 * (more_screen.dart), but the generic device sync path had no server-side
 * check at all.
 */
class SyncAssetEscalationGuardTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId, User $user): string
    {
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

    private function pushAsset(string $token, string $tenantId, string $assetId, string $userId): TestResponse
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
                        'acquisition_date' => now()->toDateString(),
                        'acquisition_cost' => 50000,
                        'salvage_value' => 5000,
                        'useful_life_months' => 60,
                        'funding_method' => 'cash',
                        'status' => 'active',
                        'created_by_user_id' => $userId,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_a_cashier_cannot_fabricate_an_asset_acquisition(): void
    {
        $tenantId = 'tenant-asset-escalation';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->seed(RolesAndPermissionsSeeder::class);

        $cashier = User::factory()->create(['email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $assetId = (string) Str::uuid();
        $response = $this->pushAsset($token, $tenantId, $assetId, $cashier->id);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'assets: creating or editing an asset requires the business owner role.',
            $response->json('errors.0.reason'),
        );
        $this->assertSame(0, Asset::where('id', $assetId)->count());
    }

    public function test_a_manager_cannot_fabricate_an_asset_acquisition_either(): void
    {
        $tenantId = 'tenant-asset-manager';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = User::factory()->create(['email' => $tenantId.'-manager@example.com']);
        $manager->assignRole('manager');
        $token = $this->actingDeviceToken($tenantId, $manager);

        $assetId = (string) Str::uuid();
        $response = $this->pushAsset($token, $tenantId, $assetId, $manager->id);

        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(0, Asset::where('id', $assetId)->count());
    }

    public function test_the_business_owner_can_legitimately_create_an_asset(): void
    {
        $tenantId = 'tenant-asset-owner';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->seed(RolesAndPermissionsSeeder::class);

        $owner = User::factory()->create(['email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $assetId = (string) Str::uuid();
        $response = $this->pushAsset($token, $tenantId, $assetId, $owner->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertSame(1, Asset::where('id', $assetId)->count());
    }
}
