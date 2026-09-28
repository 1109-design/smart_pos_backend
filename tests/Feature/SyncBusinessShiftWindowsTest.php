<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncBusinessShiftWindowsTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => '99999999-9999-4999-9999-999999999999',
            'email' => 'sync-shift-windows-owner@example.com',
        ]);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_day_and_night_shift_start_persist_via_sync_push(): void
    {
        $tenantId = 'tenant-shift-windows-1';
        $token = $this->actingDeviceToken($tenantId);

        Business::create(['id' => $tenantId, 'name' => 'Overnight Bar']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'businesses',
                    'uuid' => $tenantId,
                    'operation' => 'upsert',
                    'payload' => [
                        'name' => 'Overnight Bar',
                        'day_shift_start' => '06:00',
                        'night_shift_start' => '20:00',
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('businesses', [
            'id' => $tenantId,
            'day_shift_start' => '06:00',
            'night_shift_start' => '20:00',
        ]);
    }

    public function test_shift_windows_default_to_null_and_dont_block_normal_pushes(): void
    {
        $tenantId = 'tenant-shift-windows-2';
        $token = $this->actingDeviceToken($tenantId);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'businesses',
                    'uuid' => $tenantId,
                    'operation' => 'upsert',
                    'payload' => [
                        'name' => 'Day-Only Shop',
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $this->assertDatabaseHas('businesses', [
            'id' => $tenantId,
            'name' => 'Day-Only Shop',
            'day_shift_start' => null,
            'night_shift_start' => null,
        ]);
    }

    /**
     * The exact "full-row-upsert reset footgun" this class's own comment
     * describes as having already happened twice on the Flutter side
     * (fiscalisation_enabled/tin, then day_shift_start/night_shift_start) —
     * but that fix only ever lived client-side in businessSyncPayload().
     * Reproduced live against a running dev server: a device pushing
     * nothing but a phone number change silently reset
     * fiscalisation_enabled to false and tin to null for a real fiscalised
     * business, because nothing on the server preserved fields the payload
     * simply omitted. Fixed with a server-side preserve-if-absent gate in
     * the 'businesses' case, independent of what any given client sends.
     */
    public function test_a_partial_push_does_not_reset_fiscalisation_or_tin(): void
    {
        $tenantId = 'tenant-shift-windows-3';
        $token = $this->actingDeviceToken($tenantId);

        Business::create([
            'id' => $tenantId,
            'name' => 'Fiscalised Shop',
            'fiscalisation_enabled' => true,
            'tin' => '1234567890',
        ]);

        // A device pushes an update touching only the phone number —
        // exactly what a "cashier updates the business phone" flow (or any
        // other partial-field save) would send.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'businesses',
                    'uuid' => $tenantId,
                    'operation' => 'upsert',
                    'payload' => [
                        'name' => 'Fiscalised Shop',
                        'phone' => '+263779999999',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('businesses', [
            'id' => $tenantId,
            'phone' => '+263779999999',
            'fiscalisation_enabled' => true,
            'tin' => '1234567890',
        ]);
    }
}
