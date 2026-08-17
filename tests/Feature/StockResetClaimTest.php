<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockResetClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function pairedDeviceToken(string $tenantId, User $user): string
    {
        $plain = $user->createToken('stock-reset-test')->plainTextToken;
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

    public function test_owner_can_claim_the_one_time_reset_from_a_device(): void
    {
        $tenantId = 'tenant-stock-reset-1';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => 'AB12CD']);

        $owner = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'email' => 'owner@example.com', 'is_active' => true]);
        $owner->assignRole('business_owner');
        $token = $this->pairedDeviceToken($tenantId, $owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/stock/reset-claim', ['user_id' => $owner->id]);

        $response->assertOk();
        $response->assertJson(['claimed' => true]);

        $business = Business::find($tenantId);
        $this->assertNotNull($business->stock_reset_at);
        $this->assertSame($owner->id, $business->stock_reset_by_user_id);
    }

    public function test_a_second_claim_from_any_origin_is_rejected(): void
    {
        $tenantId = 'tenant-stock-reset-2';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => 'EF34GH']);

        $owner = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'email' => 'owner2@example.com', 'is_active' => true]);
        $owner->assignRole('business_owner');
        $token = $this->pairedDeviceToken($tenantId, $owner);

        $first = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/stock/reset-claim', ['user_id' => $owner->id]);
        $first->assertOk();

        $second = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/stock/reset-claim', ['user_id' => $owner->id]);
        $second->assertStatus(409);

        // The timestamp from the first claim must survive untouched.
        $this->assertSame($first->json('at'), Business::find($tenantId)->stock_reset_at->toIso8601String());
    }

    public function test_a_reset_already_claimed_via_backoffice_blocks_the_device_claim_too(): void
    {
        $tenantId = 'tenant-stock-reset-3';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => 'IJ56KL']);

        $owner = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'email' => 'owner3@example.com', 'is_active' => true]);
        $owner->assignRole('business_owner');
        $token = $this->pairedDeviceToken($tenantId, $owner);

        // Simulate the token already consumed via the web portal.
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'stock_reset_at' => now()->subHour(), 'stock_reset_by_user_id' => (string) Str::uuid()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/stock/reset-claim', ['user_id' => $owner->id]);

        $response->assertStatus(409);
    }

    public function test_cashier_cannot_claim_the_reset(): void
    {
        $tenantId = 'tenant-stock-reset-4';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => 'MN78OP']);

        $owner = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'email' => 'owner4@example.com', 'is_active' => true]);
        $owner->assignRole('business_owner');
        $cashier = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'email' => 'cashier4@example.com', 'is_active' => true]);
        $cashier->assignRole('cashier');
        $token = $this->pairedDeviceToken($tenantId, $owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/stock/reset-claim', ['user_id' => $cashier->id]);

        $response->assertForbidden();
        $this->assertNull(Business::find($tenantId)?->stock_reset_at);
    }

    public function test_claim_cannot_be_made_for_a_user_in_another_business(): void
    {
        $tenantId = 'tenant-stock-reset-5';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => 'QR90ST']);
        Tenant::create(['id' => 'tenant-stock-reset-other', 'business_name' => 'other', 'owner_email' => 'other@example.com', 'pairing_code' => 'UV12WX']);

        $owner = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'email' => 'owner5@example.com', 'is_active' => true]);
        $owner->assignRole('business_owner');
        $outsider = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => 'tenant-stock-reset-other', 'email' => 'outsider@example.com', 'is_active' => true]);
        $outsider->assignRole('business_owner');
        $token = $this->pairedDeviceToken($tenantId, $owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/stock/reset-claim', ['user_id' => $outsider->id]);

        $response->assertNotFound();
        $this->assertNull(Business::find($tenantId)?->stock_reset_at);
        $this->assertNull(Business::find('tenant-stock-reset-other')?->stock_reset_at);
    }
}
