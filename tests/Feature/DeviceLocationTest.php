<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers device → location assignment: platform admins lock a device to a
 * shop/warehouse from the web portal's Devices page, and the device's own
 * subscription heartbeat (which it already polls regularly) picks it up.
 */
class DeviceLocationTest extends TestCase
{
    use RefreshDatabase;

    private string $lastPlainToken = '';

    private function makeTenantWithDevice(string $tenantId): Device
    {
        Tenant::create([
            'id' => $tenantId,
            'business_name' => 'Test Business '.$tenantId,
            'owner_email' => $tenantId.'@example.com',
            'tier' => 'pro',
            'subscription_valid_until' => now()->addMonth(),
        ]);

        $user = User::factory()->create();
        $plain = $user->createToken('test-device')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        $device = Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        $this->lastPlainToken = $plain;

        return $device;
    }

    public function test_platform_admin_can_assign_device_to_a_location(): void
    {
        $admin = User::factory()->create(['business_id' => null]);
        $device = $this->makeTenantWithDevice('tenant-device-loc-1');
        $location = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-device-loc-1',
            'name' => 'Main Shop',
            'type' => 'shop',
        ]);

        $response = $this->actingAs($admin)->put(
            "/businesses/tenant-device-loc-1/devices/{$device->id}/location",
            ['location_id' => $location->id]
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'location_id' => $location->id,
        ]);
    }

    public function test_platform_admin_can_clear_device_location(): void
    {
        $admin = User::factory()->create(['business_id' => null]);
        $device = $this->makeTenantWithDevice('tenant-device-loc-2');
        $location = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-device-loc-2',
            'name' => 'Main Shop',
            'type' => 'shop',
        ]);
        $device->update(['location_id' => $location->id]);

        $response = $this->actingAs($admin)->put(
            "/businesses/tenant-device-loc-2/devices/{$device->id}/location",
            ['location_id' => null]
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'location_id' => null,
        ]);
    }

    public function test_cannot_assign_a_location_belonging_to_another_business(): void
    {
        $admin = User::factory()->create(['business_id' => null]);
        $device = $this->makeTenantWithDevice('tenant-device-loc-3');
        $foreignLocation = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'some-other-business',
            'name' => 'Foreign Shop',
            'type' => 'shop',
        ]);

        $response = $this->actingAs($admin)->put(
            "/businesses/tenant-device-loc-3/devices/{$device->id}/location",
            ['location_id' => $foreignLocation->id]
        );

        $response->assertForbidden();
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'location_id' => null,
        ]);
    }

    public function test_subscription_heartbeat_reports_assigned_location(): void
    {
        $device = $this->makeTenantWithDevice('tenant-device-loc-4');
        $location = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-device-loc-4',
            'name' => 'Downtown Shop',
            'type' => 'shop',
        ]);
        $device->update(['location_id' => $location->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->getJson('/api/v1/subscription/status');

        $response->assertOk();
        $response->assertJson([
            'assigned_location_id' => $location->id,
            'assigned_location_name' => 'Downtown Shop',
        ]);
    }

    public function test_subscription_heartbeat_reports_null_when_unassigned(): void
    {
        $device = $this->makeTenantWithDevice('tenant-device-loc-5');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->getJson('/api/v1/subscription/status');

        $response->assertOk();
        $response->assertJson([
            'assigned_location_id' => null,
            'assigned_location_name' => null,
        ]);
    }
}
