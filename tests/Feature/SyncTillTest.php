<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
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
}
