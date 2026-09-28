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

class SyncTillTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);

        return $this->deviceTokenFor($tenantId, $user);
    }

    private function deviceTokenFor(string $tenantId, User $user): string
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

    public function test_till_can_be_pushed_and_pulled_through_the_generic_sync_endpoints(): void
    {
        $tenantId = 'tenant-sync-till-1';
        $token = $this->actingDeviceToken($tenantId);

        $tillId = (string) Str::uuid();
        $locationId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'tills',
                    'uuid' => $tillId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => $locationId,
                        'name' => 'Till 1',
                        'register_number' => 1,
                        'is_active' => true,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');

        $this->assertDatabaseHas('tills', [
            'id' => $tillId,
            'business_id' => $tenantId,
            'location_id' => $locationId,
            'register_number' => 1,
        ]);

        $pull = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull?'.http_build_query(['tables' => ['tills']]));

        $pull->assertOk();
        // The pushing device's own record is excluded from its own pull (echo
        // suppression) — a *different* device on the same business should see it.
        $otherDeviceToken = $this->actingDeviceToken('tenant-sync-till-1-other-device-owner');
        Device::where('tenant_id', 'tenant-sync-till-1-other-device-owner')->update(['tenant_id' => $tenantId]);

        $pullFromOtherDevice = $this->withHeader('Authorization', 'Bearer '.$otherDeviceToken)
            ->getJson('/api/v1/sync/pull?'.http_build_query(['tables' => ['tills']]));

        $pullFromOtherDevice->assertOk();
        $this->assertContains($tillId, collect($pullFromOtherDevice->json('records'))->pluck('record_uuid')->all());
    }

    /**
     * A till deactivation is a soft delete (is_active=false, mirroring
     * locations/categories/coupons) — Till.is_active exists specifically so a
     * register can be retired without losing its shift/cash-movement history.
     */
    public function test_till_delete_soft_deletes_via_is_active(): void
    {
        $tenantId = 'tenant-sync-till-2';
        $token = $this->actingDeviceToken($tenantId);
        $till = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => (string) Str::uuid(),
            'name' => 'Till 1',
            'register_number' => 1,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'tills',
                    'uuid' => $till->id,
                    'operation' => 'delete',
                    'payload' => [],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('tills', ['id' => $till->id, 'is_active' => false]);
    }

    public function test_shift_pushed_with_till_id_keeps_it(): void
    {
        $tenantId = 'tenant-sync-till-3';
        $token = $this->actingDeviceToken($tenantId);

        $shiftId = (string) Str::uuid();
        $tillId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'shifts',
                    'uuid' => $shiftId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => (string) Str::uuid(),
                        'till_id' => $tillId,
                        'cashier_id' => (string) Str::uuid(),
                        'opened_at' => now()->toIso8601String(),
                        'status' => 'open',
                        'opening_float' => 50,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertSame($tillId, Shift::find($shiftId)->till_id);
    }

    public function test_till_cash_movement_can_be_pushed_and_is_immutable(): void
    {
        $tenantId = 'tenant-sync-till-4';
        $token = $this->actingDeviceToken($tenantId);

        $movementId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'till_cash_movements',
                    'uuid' => $movementId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => (string) Str::uuid(),
                        'till_id' => (string) Str::uuid(),
                        'type' => 'cash_in',
                        'amount' => 100,
                        'recorded_by_user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('till_cash_movements', ['id' => $movementId, 'type' => 'cash_in']);

        // Deletes on ledger tables are ignored — see SyncProcessor::IMMUTABLE.
        $deleteResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'till_cash_movements',
                    'uuid' => $movementId,
                    'operation' => 'delete',
                    'payload' => [],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $deleteResponse->assertOk();
        $this->assertDatabaseHas('till_cash_movements', ['id' => $movementId]);
    }

    public function test_device_cannot_hijack_another_businesss_till(): void
    {
        $victimTenant = 'tenant-sync-till-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $victimTill = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $victimTenant,
            'location_id' => (string) Str::uuid(),
            'name' => 'Victim Till',
            'register_number' => 1,
        ]);

        $attackerToken = $this->actingDeviceToken('tenant-sync-till-attacker');

        $response = $this->withHeader('Authorization', 'Bearer '.$attackerToken)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'tills',
                    'uuid' => $victimTill->id,
                    'operation' => 'upsert',
                    'payload' => ['business_id' => 'tenant-sync-till-attacker', 'location_id' => (string) Str::uuid(), 'name' => 'Hijacked', 'register_number' => 1],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'accepted');
        $response->assertJsonCount(1, 'errors');
        $this->assertDatabaseHas('tills', ['id' => $victimTill->id, 'name' => 'Victim Till']);
    }

    /**
     * Regression for the "till reassignment is only safe through the
     * authorized BackOffice endpoint" fix: a device pushing a payload for a
     * till it's fully entitled to sync (same business) can still update its
     * name/active flag, but cannot silently move it to a different
     * location_id — that's reserved for TillsController::reassignLocation.
     */
    public function test_device_cannot_move_an_existing_till_to_a_different_location_via_sync_push(): void
    {
        $tenantId = 'tenant-sync-till-no-relocate';
        $token = $this->actingDeviceToken($tenantId);
        $originalLocationId = (string) Str::uuid();
        $till = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $originalLocationId,
            'name' => 'Front Counter',
            'register_number' => 1,
            'is_active' => true,
        ]);

        $attemptedLocationId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'tills',
                    'uuid' => $till->id,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => $attemptedLocationId,
                        'name' => 'Front Counter Renamed',
                        'register_number' => 1,
                        'is_active' => true,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');

        // Name change went through — only location_id was refused.
        $this->assertDatabaseHas('tills', [
            'id' => $till->id,
            'name' => 'Front Counter Renamed',
            'location_id' => $originalLocationId,
        ]);
    }

    /**
     * Regression: the mismatch check used to read
     * `$payload['location_id'] ?? null`, so a push that omitted location_id
     * (or sent it as null) produced a null $tillLocationId that also failed
     * the guard's `!== null` condition — skipping authorization entirely and
     * then writing that null straight into the till's location_id. An
     * untrusted device with no special permission could silently clear an
     * existing till's location this way.
     */
    public function test_an_omitted_or_null_location_id_does_not_clear_an_existing_tills_location(): void
    {
        $tenantId = 'tenant-sync-till-null-location';
        $token = $this->actingDeviceToken($tenantId);
        $originalLocationId = (string) Str::uuid();
        $till = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $originalLocationId,
            'name' => 'Front Counter',
            'register_number' => 1,
            'is_active' => true,
        ]);

        // Omitted entirely.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'tills',
                    'uuid' => $till->id,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'name' => 'Front Counter',
                        'register_number' => 1,
                        'is_active' => true,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
        $response->assertOk();
        $this->assertSame($originalLocationId, $till->fresh()->location_id);

        // Explicitly null.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'tills',
                    'uuid' => $till->id,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => null,
                        'name' => 'Front Counter',
                        'register_number' => 1,
                        'is_active' => true,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
        $response->assertOk();
        $this->assertSame($originalLocationId, $till->fresh()->location_id);
    }

    /**
     * Regression: the manager-authorized till-relocation path checked
     * manage_tills and open-shift status but never the acting user's own
     * location scope, so a manager restricted to one branch could relocate
     * a till between two other branches entirely outside their visibility —
     * something TillsController::reassignLocation already refuses for the
     * same action via currentLocationScope().
     */
    public function test_a_scoped_manager_cannot_relocate_a_till_outside_their_scope_via_sync_push(): void
    {
        $tenantId = 'tenant-sync-till-scoped-manager';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $ownLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Own Branch', 'type' => 'shop', 'is_active' => true]);
        $branchB = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'type' => 'shop', 'is_active' => true]);
        $branchC = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch C', 'type' => 'shop', 'is_active' => true]);

        $manager = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-manager@example.com']);
        $manager->assignRole('manager');
        $manager->locations()->attach($ownLocation->id);
        $token = $this->deviceTokenFor($tenantId, $manager);

        $till = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $branchB->id,
            'name' => 'Till 1',
            'register_number' => 1,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'tills',
                    'uuid' => $till->id,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => $branchC->id,
                        'name' => 'Till 1',
                        'register_number' => 1,
                        'is_active' => true,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertSame($branchB->id, $till->fresh()->location_id);
    }

    /**
     * The manager-authorized relocation path should still work when the
     * manager's scope actually covers both the till's current and target
     * location — the scope check must not turn into a blanket refusal.
     */
    public function test_a_scoped_manager_can_relocate_a_till_between_their_own_locations_via_sync_push(): void
    {
        $tenantId = 'tenant-sync-till-scoped-manager-ok';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $branchA = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch A', 'type' => 'shop', 'is_active' => true]);
        $branchB = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'type' => 'shop', 'is_active' => true]);

        $manager = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-manager-ok@example.com']);
        $manager->assignRole('manager');
        $manager->locations()->attach([$branchA->id, $branchB->id]);
        $token = $this->deviceTokenFor($tenantId, $manager);

        $till = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $branchA->id,
            'name' => 'Till 1',
            'register_number' => 1,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'tills',
                    'uuid' => $till->id,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => $branchB->id,
                        'name' => 'Till 1',
                        'register_number' => 1,
                        'is_active' => true,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertSame($branchB->id, $till->fresh()->location_id);
    }
}
