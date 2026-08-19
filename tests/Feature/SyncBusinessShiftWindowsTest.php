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
}
