<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncShiftTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => '99999999-9999-4999-9999-999999999999',
            'email' => 'sync-shift-owner@example.com',
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

    /**
     * Regression test: Shift::$fillable was missing location_id, so every
     * shift synced from a till landed with location_id = null even though
     * the device sent it and SyncProcessor forwarded it — see
     * app/Models/Shift.php.
     */
    public function test_shift_pushed_from_a_device_keeps_its_location_id(): void
    {
        $tenantId = 'tenant-sync-shift-1';
        $token = $this->actingDeviceToken($tenantId);

        $shiftId = (string) Str::uuid();
        $locationId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'shifts',
                    'uuid' => $shiftId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => $locationId,
                        'cashier_id' => '99999999-9999-4999-9999-999999999999',
                        'opened_at' => now()->toIso8601String(),
                        'status' => 'open',
                        'opening_float' => 50,
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('shifts', [
            'id' => $shiftId,
            'business_id' => $tenantId,
            'location_id' => $locationId,
        ]);

        $this->assertSame($locationId, Shift::find($shiftId)->location_id);
    }
}
