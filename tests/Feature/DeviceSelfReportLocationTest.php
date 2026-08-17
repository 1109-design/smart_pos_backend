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
 * Covers the device-side counterpart to admin location assignment: when no
 * admin has assigned a location from the web portal, the POS app prompts
 * the cashier to pick one (the shift-open fallback), and that pick must be
 * reported back to the server so the Devices page reflects reality instead
 * of showing "No restriction" for a till that's actually been self-scoped.
 */
class DeviceSelfReportLocationTest extends TestCase
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

    public function test_device_can_report_a_self_picked_location_when_unassigned(): void
    {
        $device = $this->makeTenantWithDevice('tenant-self-report-1');
        $location = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-self-report-1',
            'name' => 'Shop Front',
            'type' => 'shop',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->putJson('/api/v1/device/location', ['location_id' => $location->id]);

        $response->assertOk();
        $response->assertJson(['location_id' => $location->id]);
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'location_id' => $location->id,
        ]);
    }

    public function test_self_report_does_not_override_an_existing_admin_assignment(): void
    {
        $device = $this->makeTenantWithDevice('tenant-self-report-2');
        $adminAssigned = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-self-report-2',
            'name' => 'Warehouse',
            'type' => 'warehouse',
        ]);
        $device->update(['location_id' => $adminAssigned->id]);

        $cashierPicked = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-self-report-2',
            'name' => 'Shop Front',
            'type' => 'shop',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->putJson('/api/v1/device/location', ['location_id' => $cashierPicked->id]);

        $response->assertOk();
        $response->assertJson(['location_id' => $adminAssigned->id]);
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'location_id' => $adminAssigned->id,
        ]);
    }

    public function test_cannot_self_report_a_location_belonging_to_another_business(): void
    {
        $device = $this->makeTenantWithDevice('tenant-self-report-3');
        $foreignLocation = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'some-other-business',
            'name' => 'Foreign Shop',
            'type' => 'shop',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->putJson('/api/v1/device/location', ['location_id' => $foreignLocation->id]);

        $response->assertForbidden();
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'location_id' => null,
        ]);
    }

    public function test_revoked_device_cannot_self_report_a_location(): void
    {
        $device = $this->makeTenantWithDevice('tenant-self-report-4');
        $device->update(['is_revoked' => true]);
        $location = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-self-report-4',
            'name' => 'Shop Front',
            'type' => 'shop',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->putJson('/api/v1/device/location', ['location_id' => $location->id]);

        $response->assertForbidden();
    }
}
