<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeAccessTest extends TestCase
{
    use RefreshDatabase;

    private function pairedDeviceToken(string $tenantId, User $user): string
    {
        $plain = $user->createToken('access-test')->plainTextToken;
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

    public function test_owner_can_set_backoffice_password_from_paired_device(): void
    {
        $tenantId = 'tenant-access-1';
        Tenant::create(['id' => $tenantId, 'pairing_code' => 'AB12CD']);

        $owner = User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'email' => 'owner-access@example.com',
            'is_active' => true,
            'role' => 'business_owner',
        ]);
        $token = $this->pairedDeviceToken($tenantId, $owner);

        $info = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/backoffice/info');
        $info->assertOk()
            ->assertJsonPath('pairing_code', 'AB12CD')
            ->assertJsonStructure(['portal_url', 'pairing_code', 'business_name']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/backoffice/password', [
                'user_id' => $owner->id,
                'password' => 'super-secret-9',
            ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('super-secret-9', $owner->fresh()->password));
    }

    public function test_cashier_cannot_get_a_backoffice_password(): void
    {
        $tenantId = 'tenant-access-2';
        Tenant::create(['id' => $tenantId, 'pairing_code' => 'EF34GH']);

        $owner = User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'email' => 'owner-access-2@example.com',
            'is_active' => true,
            'role' => 'business_owner',
        ]);
        $cashier = User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'email' => 'cashier-access@example.com',
            'is_active' => true,
            'role' => 'cashier',
        ]);
        $token = $this->pairedDeviceToken($tenantId, $owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/backoffice/password', [
                'user_id' => $cashier->id,
                'password' => 'super-secret-9',
            ]);

        $response->assertForbidden();
    }

    public function test_password_cannot_be_set_for_a_user_in_another_business(): void
    {
        $tenantId = 'tenant-access-3';
        Tenant::create(['id' => $tenantId, 'pairing_code' => 'IJ56KL']);
        Tenant::create(['id' => 'tenant-access-other', 'pairing_code' => 'MN78OP']);

        $owner = User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'email' => 'owner-access-3@example.com',
            'is_active' => true,
            'role' => 'business_owner',
        ]);
        $outsider = User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-access-other',
            'email' => 'outsider@example.com',
            'is_active' => true,
            'role' => 'business_owner',
        ]);
        $token = $this->pairedDeviceToken($tenantId, $owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/backoffice/password', [
                'user_id' => $outsider->id,
                'password' => 'super-secret-9',
            ]);

        $response->assertNotFound();
    }

    public function test_registration_requires_a_password_and_it_works_for_backoffice_login(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'device_identifier' => (string) Str::uuid(),
            'device_name' => 'Reg Test Device',
            'business_name' => 'Register Test Shop',
            'owner_name' => 'Owner Reg',
            'owner_email' => 'reg-owner@example.com',
            'pin' => '1234',
            'password' => 'portal-pass-1',
            'currency_code' => 'USD',
        ]);

        $response->assertCreated();

        $owner = User::where('email', 'reg-owner@example.com')->first();
        $this->assertNotNull($owner);
        $this->assertTrue(Hash::check('portal-pass-1', $owner->password));

        // Missing password must be rejected.
        $this->postJson('/api/v1/auth/register', [
            'device_identifier' => (string) Str::uuid(),
            'device_name' => 'Reg Test Device 2',
            'business_name' => 'Register Test Shop 2',
            'owner_name' => 'Owner Reg 2',
            'owner_email' => 'reg-owner-2@example.com',
            'pin' => '1234',
            'currency_code' => 'USD',
        ])->assertUnprocessable();
    }
}
