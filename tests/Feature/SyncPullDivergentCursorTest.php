<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SyncCursor;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sync/concurrency audit follow-up: pull() bounded a multi-table request
 * with a single `synced_at > cursors->min()` threshold, so a device that
 * requested several tables with divergent cursor ages (routine for a
 * realtime quickPullTables() call — one table advanced by frequent
 * activity, another stale) re-fetched records for the already-current
 * table that it had already pulled and applied. Idempotent, not data
 * corruption, but wasted bandwidth/processing on every such call. Fixed to
 * bound each table by its own cursor.
 */
class SyncPullDivergentCursorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: string} [deviceId, plainToken]
     */
    private function actingDevice(string $tenantId): array
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        $device = Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return [$device->id, $plain];
    }

    public function test_a_current_table_is_not_refetched_because_another_table_in_the_same_request_lags_behind(): void
    {
        $tenantId = 'tenant-pull-cursor-1';
        [$deviceId, $token] = $this->actingDevice($tenantId);

        // 'products' churns constantly and this device is fully caught up
        // on it; 'suppliers' hasn't changed in a while, so its cursor sits
        // far behind. Both requested together, as quickPullTables() would.
        $oldProductRecord = SyncRecord::create([
            'business_id' => $tenantId,
            'table_name' => 'products',
            'record_uuid' => (string) Str::uuid(),
            'operation' => 'upsert',
            'payload' => ['name' => 'Old Product Write'],
            'source_updated_at' => now()->subMinutes(30),
            'synced_at' => now()->subMinutes(30),
        ]);

        SyncCursor::create([
            'device_id' => $deviceId,
            'table_name' => 'products',
            'last_pulled_at' => now()->subMinutes(20),
        ]);
        SyncCursor::create([
            'device_id' => $deviceId,
            'table_name' => 'suppliers',
            'last_pulled_at' => now()->subHours(5),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull?'.http_build_query([
                'tables' => ['products', 'suppliers'],
            ]));

        $response->assertOk();
        $uuids = collect($response->json('records'))->pluck('record_uuid');

        $this->assertFalse(
            $uuids->contains($oldProductRecord->record_uuid),
            'the already-pulled products record must not be re-delivered just because suppliers lags behind'
        );
    }

    public function test_a_table_with_no_cursor_yet_still_gets_its_full_history(): void
    {
        $tenantId = 'tenant-pull-cursor-2';
        [$deviceId, $token] = $this->actingDevice($tenantId);

        $oldBankAccountRecord = SyncRecord::create([
            'business_id' => $tenantId,
            'table_name' => 'bank_accounts',
            'record_uuid' => (string) Str::uuid(),
            'operation' => 'upsert',
            'payload' => ['name' => 'Old Bank Account'],
            'source_updated_at' => now()->subDays(10),
            'synced_at' => now()->subDays(10),
        ]);

        // This device has synced 'products' for a while, but has never
        // pulled 'bank_accounts' before — it must not be bounded by
        // products' cursor.
        SyncCursor::create([
            'device_id' => $deviceId,
            'table_name' => 'products',
            'last_pulled_at' => now()->subMinutes(1),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull?'.http_build_query([
                'tables' => ['products', 'bank_accounts'],
            ]));

        $response->assertOk();
        $uuids = collect($response->json('records'))->pluck('record_uuid');

        $this->assertTrue(
            $uuids->contains($oldBankAccountRecord->record_uuid),
            'a table pulled for the first time must get its full history, not be bounded by an unrelated table\'s cursor'
        );
    }
}
