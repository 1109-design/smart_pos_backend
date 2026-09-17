<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Location;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
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

    private function makeTenantWithDevice(string $tenantId, ?string $role = null): Device
    {
        Tenant::create([
            'id' => $tenantId,
            'business_name' => 'Test Business '.$tenantId,
            'owner_email' => $tenantId.'@example.com',
            'tier' => 'pro',
            'subscription_valid_until' => now()->addMonth(),
        ]);

        if ($role !== null) {
            $this->seed(RolesAndPermissionsSeeder::class);
        }
        $user = User::factory()->create();
        if ($role !== null) {
            $user->assignRole($role);
        }
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

    public function test_force_repoints_a_device_already_locked_to_a_different_location(): void
    {
        $device = $this->makeTenantWithDevice('tenant-self-report-force-1', 'manager');
        $adminAssigned = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-self-report-force-1',
            'name' => 'Warehouse',
            'type' => 'warehouse',
        ]);
        $device->update(['location_id' => $adminAssigned->id]);

        $ownerPicked = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-self-report-force-1',
            'name' => 'Shop Front',
            'type' => 'shop',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->putJson('/api/v1/device/location', [
                'location_id' => $ownerPicked->id,
                'force' => true,
            ]);

        $response->assertOk();
        $response->assertJson(['location_id' => $ownerPicked->id]);
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'location_id' => $ownerPicked->id,
        ]);
    }

    public function test_force_repoints_for_the_business_owner_too(): void
    {
        // Regression guard for the role-key mismatch: Dart's
        // UserRole.owner.key is 'owner', not Spatie's 'business_owner' — the
        // controller must special-case the Spatie role name rather than
        // looking it up in role_permissions like every other role.
        $device = $this->makeTenantWithDevice('tenant-self-report-force-owner', 'business_owner');
        $adminAssigned = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-self-report-force-owner',
            'name' => 'Warehouse',
            'type' => 'warehouse',
        ]);
        $device->update(['location_id' => $adminAssigned->id]);

        $ownerPicked = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-self-report-force-owner',
            'name' => 'Shop Front',
            'type' => 'shop',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->putJson('/api/v1/device/location', [
                'location_id' => $ownerPicked->id,
                'force' => true,
            ]);

        $response->assertOk();
        $response->assertJson(['location_id' => $ownerPicked->id]);
    }

    /**
     * Regression: `force: true` used to be honored purely on the client's
     * say-so — the docblock even called it "enforced client-side" since the
     * device token has no notion of which staff member is currently signed
     * in. Anyone holding the device's bearer token could flip an
     * admin-locked device's location regardless of the role that token's
     * own last server login actually authenticated as.
     */
    public function test_force_is_ignored_when_the_devices_last_authenticated_user_has_no_override_permission(): void
    {
        $device = $this->makeTenantWithDevice('tenant-self-report-force-denied', 'cashier');
        $adminAssigned = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-self-report-force-denied',
            'name' => 'Warehouse',
            'type' => 'warehouse',
        ]);
        $device->update(['location_id' => $adminAssigned->id]);

        $cashierPicked = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-self-report-force-denied',
            'name' => 'Shop Front',
            'type' => 'shop',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->putJson('/api/v1/device/location', [
                'location_id' => $cashierPicked->id,
                'force' => true,
            ]);

        $response->assertOk();
        // force was denied server-side, so this degrades to a plain
        // self-pick — which itself is a no-op while an admin lock stands.
        $response->assertJson(['location_id' => $adminAssigned->id]);
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'location_id' => $adminAssigned->id,
        ]);
    }

    /**
     * A Cashier explicitly granted Permission.overrideLocationLock (synced
     * up from the till app's own Roles & Permissions screen into
     * role_permissions) must still be able to force an override — the
     * server-side floor mirrors whatever was actually granted, it doesn't
     * just hardcode Owner/Manager.
     */
    public function test_force_works_for_a_cashier_explicitly_granted_the_override_permission(): void
    {
        $device = $this->makeTenantWithDevice('tenant-self-report-force-granted', 'cashier');
        RolePermission::create([
            'business_id' => 'tenant-self-report-force-granted',
            'role' => 'cashier',
            'permissions_json' => ['makeSale', 'overrideLocationLock'],
        ]);

        $adminAssigned = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-self-report-force-granted',
            'name' => 'Warehouse',
            'type' => 'warehouse',
        ]);
        $device->update(['location_id' => $adminAssigned->id]);

        $cashierPicked = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-self-report-force-granted',
            'name' => 'Shop Front',
            'type' => 'shop',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->putJson('/api/v1/device/location', [
                'location_id' => $cashierPicked->id,
                'force' => true,
            ]);

        $response->assertOk();
        $response->assertJson(['location_id' => $cashierPicked->id]);
    }

    public function test_force_cannot_repoint_to_a_location_belonging_to_another_business(): void
    {
        $device = $this->makeTenantWithDevice('tenant-self-report-force-2');
        $adminAssigned = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-self-report-force-2',
            'name' => 'Warehouse',
            'type' => 'warehouse',
        ]);
        $device->update(['location_id' => $adminAssigned->id]);

        $foreignLocation = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'some-other-business',
            'name' => 'Foreign Shop',
            'type' => 'shop',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->lastPlainToken)
            ->putJson('/api/v1/device/location', [
                'location_id' => $foreignLocation->id,
                'force' => true,
            ]);

        $response->assertForbidden();
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
