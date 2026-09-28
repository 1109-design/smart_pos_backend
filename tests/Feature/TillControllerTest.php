<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TillControllerTest extends TestCase
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

    public function test_index_lists_only_active_tills_for_the_location_ordered_by_register_number(): void
    {
        $tenantId = 'tenant-till-controller-1';
        $token = $this->actingDeviceToken($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Main Shop']);

        Till::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $location->id, 'name' => 'Till 2', 'register_number' => 2]);
        Till::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $location->id, 'name' => 'Till 1', 'register_number' => 1]);
        Till::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $location->id, 'name' => 'Retired Till', 'register_number' => 3, 'is_active' => false]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/locations/{$location->id}/tills");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $this->assertSame(['Till 1', 'Till 2'], collect($response->json('data'))->pluck('name')->all());
    }

    public function test_index_rejects_a_location_belonging_to_another_business(): void
    {
        $victimTenant = 'tenant-till-controller-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $victimLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $victimTenant, 'name' => 'Victim Shop']);

        $attackerToken = $this->actingDeviceToken('tenant-till-controller-attacker');

        $response = $this->withHeader('Authorization', 'Bearer '.$attackerToken)
            ->getJson("/api/v1/locations/{$victimLocation->id}/tills");

        $response->assertNotFound();
    }
}
