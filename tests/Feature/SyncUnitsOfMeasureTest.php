<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Offline-first audit follow-up — the "Unit of Measure" picker was a
 * hardcoded list in the Flutter app with no way for a business to add its
 * own. `units_of_measure` mirrors `categories`' shape exactly (business-
 * owned, syncable, soft-deletable via is_active) so a custom unit added on
 * one till reaches every other device and BackOffice.
 */
class SyncUnitsOfMeasureTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(),
            'email' => 'sync-uom-owner-'.$tenantId.'@example.com',
        ]);

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

    public function test_a_custom_unit_pushed_from_a_till_is_persisted(): void
    {
        $tenantId = 'tenant-uom-1';
        $token = $this->actingDeviceToken($tenantId);
        $unitId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'units_of_measure',
                    'uuid' => $unitId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'name' => 'roll',
                        'is_active' => true,
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('units_of_measure', [
            'id' => $unitId,
            'business_id' => $tenantId,
            'name' => 'roll',
        ]);
    }

    public function test_deleting_a_unit_soft_deletes_it_via_is_active(): void
    {
        $tenantId = 'tenant-uom-2';
        $token = $this->actingDeviceToken($tenantId);
        $unitId = (string) Str::uuid();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'units_of_measure',
                    'uuid' => $unitId,
                    'operation' => 'upsert',
                    'payload' => ['business_id' => $tenantId, 'name' => 'roll'],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'units_of_measure',
                    'uuid' => $unitId,
                    'operation' => 'delete',
                    'payload' => ['business_id' => $tenantId],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $this->assertDatabaseHas('units_of_measure', ['id' => $unitId, 'is_active' => false]);
    }

    /**
     * A device is authorized for exactly one tenant, so a payload claiming a
     * different business_id must never be trusted at face value — see the
     * fix in SyncController::push() (the enrichment step now *always*
     * overrides business_id to the authenticated device's own tenant,
     * regardless of what the payload claims). Before that fix, this would
     * have created a fabricated row inside "someone-elses-business" — a
     * cross-tenant data-injection hole affecting every TENANT_SCOPED_MODELS
     * table for brand-new records, since assertOwnership() only guards
     * *existing* rows (there's nothing in the database yet to check a new
     * uuid's claimed owner against).
     */
    public function test_a_unit_cannot_be_pushed_for_another_business(): void
    {
        $tenantId = 'tenant-uom-3';
        $token = $this->actingDeviceToken($tenantId);
        $unitId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'units_of_measure',
                    'uuid' => $unitId,
                    'operation' => 'upsert',
                    'payload' => ['business_id' => 'someone-elses-business', 'name' => 'roll'],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');
        $response->assertJsonCount(0, 'errors');

        $this->assertDatabaseHas('units_of_measure', [
            'id' => $unitId,
            'business_id' => $tenantId,
        ]);
        $this->assertDatabaseMissing('units_of_measure', [
            'id' => $unitId,
            'business_id' => 'someone-elses-business',
        ]);
    }
}
