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
 * Startup-sync architecture spec §16 ("the server should be able to tell a
 * device its local state may be inconsistent"): pull() now flags a
 * requested table in `reconciliation_required` when the client's claimed
 * `since`/`after_id` position doesn't match anything this server ever
 * actually recorded delivering to this same device (see
 * SyncController::detectReconciliationRequired()'s doc comment). The client
 * resets only that table's local cursor on seeing the flag — never a full
 * wipe, and the outbox is untouched.
 */
class SyncPullReconciliationSignalTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: string} [deviceId, plainToken] */
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

    public function test_a_device_simply_behind_is_never_flagged(): void
    {
        $tenantId = 'tenant-reconcile-1';
        [$deviceId, $token] = $this->actingDevice($tenantId);

        SyncCursor::create([
            'device_id' => $deviceId,
            'table_name' => 'products',
            'last_pulled_at' => now()->subMinutes(1),
            'last_pulled_id' => 500,
        ]);

        // Ordinary lag: the device claims an older position than the server
        // last recorded giving it.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull?'.http_build_query([
                'tables' => ['products'],
                'since' => now()->subMinutes(10)->toIso8601String(),
                'after_id' => 100,
            ]));

        $response->assertOk();
        $this->assertSame([], $response->json('reconciliation_required'));
    }

    public function test_a_device_claiming_a_position_ahead_of_what_the_server_ever_sent_it_is_flagged(): void
    {
        $tenantId = 'tenant-reconcile-2';
        [$deviceId, $token] = $this->actingDevice($tenantId);

        SyncCursor::create([
            'device_id' => $deviceId,
            'table_name' => 'products',
            'last_pulled_at' => now()->subMinutes(30),
            'last_pulled_id' => 100,
        ]);

        // Anomaly: the device claims a NEWER position than the server has
        // any record of ever delivering to it — e.g. a local DB restored
        // from an unrelated device/backup.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull?'.http_build_query([
                'tables' => ['products'],
                'since' => now()->toIso8601String(),
                'after_id' => 999,
            ]));

        $response->assertOk();
        $this->assertSame(['products'], $response->json('reconciliation_required'));
    }

    public function test_a_device_with_no_server_side_cursor_at_all_for_a_claimed_table_is_flagged(): void
    {
        $tenantId = 'tenant-reconcile-3';
        [, $token] = $this->actingDevice($tenantId);

        // No SyncCursor row exists server-side for 'products' at all, yet
        // the device sends a `since` as if it had pulled before.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull?'.http_build_query([
                'tables' => ['products'],
                'since' => now()->subMinutes(5)->toIso8601String(),
            ]));

        $response->assertOk();
        $this->assertSame(['products'], $response->json('reconciliation_required'));
    }

    public function test_a_first_time_pull_with_no_since_is_never_flagged_even_with_no_server_cursor(): void
    {
        $tenantId = 'tenant-reconcile-4';
        [, $token] = $this->actingDevice($tenantId);

        SyncRecord::create([
            'business_id' => $tenantId,
            'table_name' => 'products',
            'record_uuid' => (string) Str::uuid(),
            'operation' => 'upsert',
            'payload' => ['name' => 'First Product'],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull?'.http_build_query([
                'tables' => ['products'],
            ]));

        $response->assertOk();
        $this->assertSame([], $response->json('reconciliation_required'));
        $this->assertCount(1, $response->json('records'));
    }
}
