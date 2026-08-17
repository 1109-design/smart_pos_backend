<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers device renaming from both directions: the device itself (so a
 * cashier can name/confirm the till they're on) and the platform admin's
 * web portal Devices page.
 */
class DeviceRenameTest extends TestCase
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
            'name' => 'SmartPOS ABC123',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        $this->lastPlainToken = $plain;

        return $device;
    }

    public function test_device_can_rename_itself(): void
    {
        $device = $this->makeTenantWithDevice('tenant-device-rename-1');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->putJson('/api/v1/device/name', ['name' => 'Till 1 — Front Counter']);

        $response->assertOk();
        $response->assertJson(['name' => 'Till 1 — Front Counter']);
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'name' => 'Till 1 — Front Counter',
        ]);
    }

    public function test_revoked_device_cannot_rename_itself(): void
    {
        $device = $this->makeTenantWithDevice('tenant-device-rename-2');
        $device->update(['is_revoked' => true]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->putJson('/api/v1/device/name', ['name' => 'Hijacked Till']);

        $response->assertForbidden();
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'name' => 'SmartPOS ABC123',
        ]);
    }

    public function test_device_name_requires_a_value(): void
    {
        $this->makeTenantWithDevice('tenant-device-rename-3');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->putJson('/api/v1/device/name', ['name' => '']);

        $response->assertUnprocessable();
    }

    public function test_platform_admin_can_rename_device_from_web_portal(): void
    {
        $admin = User::factory()->create(['business_id' => null]);
        $device = $this->makeTenantWithDevice('tenant-device-rename-4');

        $response = $this->actingAs($admin)->put(
            "/businesses/tenant-device-rename-4/devices/{$device->id}/name",
            ['name' => 'Till 2 — Warehouse Scanner']
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'name' => 'Till 2 — Warehouse Scanner',
        ]);
    }

    public function test_platform_admin_cannot_rename_a_device_belonging_to_another_business(): void
    {
        $admin = User::factory()->create(['business_id' => null]);
        $device = $this->makeTenantWithDevice('tenant-device-rename-5');

        $response = $this->actingAs($admin)->put(
            '/businesses/some-other-business/devices/'.$device->id.'/name',
            ['name' => 'Hijacked Till']
        );

        $response->assertForbidden();
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'name' => 'SmartPOS ABC123',
        ]);
    }
}
