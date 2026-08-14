<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SyncConflict;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncConflictResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => '99999999-9999-4999-9999-999999999999',
            'email' => 'sync-owner@example.com',
        ]);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_push_creates_pending_conflict_for_older_incoming_version(): void
    {
        $tenantId = 'tenant-sync-conflict-1';
        $token = $this->actingDeviceToken($tenantId);

        SyncRecord::create([
            'business_id' => $tenantId,
            'table_name' => 'categories',
            'record_uuid' => '11111111-1111-4111-8111-111111111111',
            'operation' => 'upsert',
            'payload' => ['name' => 'Server Newer'],
            'source_updated_at' => now()->addMinute(),
            'synced_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'categories',
                    'uuid' => '11111111-1111-4111-8111-111111111111',
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'name' => 'Old Device Version',
                        'updated_at' => now()->subMinute()->toIso8601String(),
                    ],
                    'updated_at' => now()->subMinute()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'conflicts');

        $this->assertDatabaseHas('sync_conflicts', [
            'business_id' => $tenantId,
            'table_name' => 'categories',
            'record_uuid' => '11111111-1111-4111-8111-111111111111',
            'status' => 'pending',
            'conflict_type' => 'version_conflict',
        ]);
    }

    public function test_manager_can_resolve_conflict_with_retry_local_action(): void
    {
        $tenantId = 'tenant-sync-conflict-2';
        $token = $this->actingDeviceToken($tenantId);

        $conflict = SyncConflict::create([
            'business_id' => $tenantId,
            'table_name' => 'categories',
            'record_uuid' => '22222222-2222-4222-8222-222222222222',
            'reason' => 'Manual review required',
            'conflict_type' => 'version_conflict',
            'local_payload' => [
                'business_id' => $tenantId,
                'name' => 'Retry Me',
                'updated_at' => now()->toIso8601String(),
            ],
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/sync/conflicts/{$conflict->id}/resolve", [
                'action' => 'retry_local',
                'updated_at' => now()->toIso8601String(),
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('sync_conflicts', [
            'id' => $conflict->id,
            'status' => 'resolved',
            'resolution_action' => 'retry_local',
        ]);

        $this->assertDatabaseHas('categories', [
            'id' => '22222222-2222-4222-8222-222222222222',
            'business_id' => $tenantId,
            'name' => 'Retry Me',
        ]);

        $this->assertDatabaseHas('sync_records', [
            'business_id' => $tenantId,
            'table_name' => 'categories',
            'record_uuid' => '22222222-2222-4222-8222-222222222222',
            'operation' => 'upsert',
        ]);
    }
}
