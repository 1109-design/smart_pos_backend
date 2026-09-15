<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Location;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the manager-authorized exception carved into the "no self-relocation"
 * guard in SyncProcessor's 'tills' case (see SyncTillTest::
 * test_device_cannot_move_an_existing_till_to_a_different_location_via_sync_push
 * for the base refusal). Flutter-first (see [[smartpos-flutter-first-mandate]])
 * means a manager reassigning a till from the till app itself — offline, then
 * synced — must work, but only when the server's own copy of the acting
 * user's role actually grants manage_tills; anything less still gets refused
 * exactly like a bare device push.
 */
class SyncTillLocationEscalationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

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

    private function pushTillLocation(string $token, string $tenantId, Till $till, string $newLocationId)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'tills',
                    'uuid' => $till->id,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => $newLocationId,
                        'name' => $till->name,
                        'register_number' => $till->register_number,
                        'is_active' => true,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_a_manager_can_move_a_till_offline_and_it_lands_on_sync(): void
    {
        $tenantId = 'tenant-till-loc-manager';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $fromLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch A', 'type' => 'shop']);
        $toLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'type' => 'shop']);
        $till = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $fromLocation->id,
            'name' => 'Front Counter',
            'register_number' => 1,
            'is_active' => true,
        ]);

        $manager = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-manager@example.com']);
        $manager->assignRole('manager');
        $token = $this->actingDeviceToken($tenantId, $manager);

        $response = $this->pushTillLocation($token, $tenantId, $till, $toLocation->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertSame($toLocation->id, Till::find($till->id)->location_id);

        // Same audit trail the BackOffice reassignment endpoint writes, so
        // the Tills page shows who moved it and when regardless of which
        // path made the change.
        $this->assertDatabaseHas('till_location_audits', [
            'till_id' => $till->id,
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'changed_by_user_id' => $manager->id,
        ]);
    }

    public function test_a_cashier_still_cannot_move_a_till_offline(): void
    {
        $tenantId = 'tenant-till-loc-cashier';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $fromLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch A', 'type' => 'shop']);
        $toLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'type' => 'shop']);
        $till = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $fromLocation->id,
            'name' => 'Front Counter',
            'register_number' => 1,
            'is_active' => true,
        ]);

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $response = $this->pushTillLocation($token, $tenantId, $till, $toLocation->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        // Accepted the record (name/flags still sync), but the location was
        // silently held at its current value — same shape as the bare-device
        // refusal in SyncTillTest.
        $this->assertSame($fromLocation->id, Till::find($till->id)->location_id);
        $this->assertDatabaseCount('till_location_audits', 0);
    }

    public function test_a_manager_cannot_move_a_till_with_an_open_shift(): void
    {
        $tenantId = 'tenant-till-loc-open-shift';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $fromLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch A', 'type' => 'shop']);
        $toLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'type' => 'shop']);
        $till = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $fromLocation->id,
            'name' => 'Front Counter',
            'register_number' => 1,
            'is_active' => true,
        ]);
        Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $fromLocation->id,
            'till_id' => $till->id,
            'cashier_id' => (string) Str::uuid(),
            'opened_at' => now(),
            'status' => 'open',
            'opening_float' => 50,
        ]);

        $manager = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-manager@example.com']);
        $manager->assignRole('manager');
        $token = $this->actingDeviceToken($tenantId, $manager);

        $response = $this->pushTillLocation($token, $tenantId, $till, $toLocation->id);

        $response->assertOk();
        $this->assertSame($fromLocation->id, Till::find($till->id)->location_id);
        $this->assertDatabaseCount('till_location_audits', 0);
    }
}
