<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LocalSyncConflict;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LAN peer-sync conflicts (resolved entirely on-device by two tills on the
 * same LAN, never touching Laravel) must still reach the server so a manager
 * reviewing sync conflicts centrally sees them — see
 * local_sync_conflict_reporter.dart on the Flutter side.
 */
class LocalSyncConflictCentralizationTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId, string $deviceName = 'Test Device'): string
    {
        if (! Tenant::query()->whereKey($tenantId)->exists()) {
            Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        }

        $user = User::factory()->create(['email' => $tenantId.'-'.Str::random(6).'@example.com']);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => $deviceName,
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function samplePayload(string $recordUuid, string $occurredAt): array
    {
        return [
            'table' => 'customers',
            'record_uuid' => $recordUuid,
            'reason' => 'updated_at tie, local device newer',
            'local_payload' => json_encode(['phone' => '0771111111']),
            'incoming_payload' => json_encode(['phone' => '0772222222']),
            'peer_device_name' => 'Till 2',
            'status' => 'resolved',
            'occurred_at' => $occurredAt,
        ];
    }

    public function test_posting_a_batch_creates_central_rows_scoped_to_the_devices_business(): void
    {
        $tenantId = 'tenant-local-conflict-1';
        $token = $this->actingDeviceToken($tenantId);
        $recordUuid = (string) Str::uuid();
        $occurredAt = now()->toIso8601String();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/local-conflicts', [
                'conflicts' => [$this->samplePayload($recordUuid, $occurredAt)],
            ]);

        $response->assertOk();
        $response->assertJson(['accepted' => 1]);

        $this->assertDatabaseHas('local_sync_conflicts', [
            'business_id' => $tenantId,
            'table_name' => 'customers',
            'record_uuid' => $recordUuid,
            'peer_device_name' => 'Till 2',
            'status' => 'resolved',
        ]);

        $row = LocalSyncConflict::where('record_uuid', $recordUuid)->first();
        $this->assertNotNull($row);
        // Payloads decoded from the Flutter-sent JSON strings into real JSON,
        // not doubly-encoded.
        $this->assertEquals(['phone' => '0771111111'], $row->local_payload);
        $this->assertEquals(['phone' => '0772222222'], $row->incoming_payload);

        $listResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/local-conflicts');
        $listResponse->assertOk();
        $listResponse->assertJsonCount(1, 'local_conflicts');
    }

    public function test_a_second_businesss_device_cannot_see_or_pollute_the_first_businesss_conflicts(): void
    {
        $victimTenant = 'tenant-local-conflict-victim';
        $victimToken = $this->actingDeviceToken($victimTenant);
        $victimRecordUuid = (string) Str::uuid();

        $this->withHeader('Authorization', 'Bearer '.$victimToken)
            ->postJson('/api/v1/sync/local-conflicts', [
                'conflicts' => [$this->samplePayload($victimRecordUuid, now()->toIso8601String())],
            ])->assertOk();

        $attackerToken = $this->actingDeviceToken('tenant-local-conflict-attacker');

        // The attacker's own list must not include the victim's row.
        $listResponse = $this->withHeader('Authorization', 'Bearer '.$attackerToken)
            ->getJson('/api/v1/sync/local-conflicts');
        $listResponse->assertOk();
        $listResponse->assertJsonCount(0, 'local_conflicts');

        // And whatever business_id the attacker's payload might have claimed
        // (there isn't one in this endpoint's contract, but prove the stored
        // row is scoped to the attacker's own tenant regardless), a fresh
        // conflict it reports lands under its own business, not the victim's.
        $attackerRecordUuid = (string) Str::uuid();
        $this->withHeader('Authorization', 'Bearer '.$attackerToken)
            ->postJson('/api/v1/sync/local-conflicts', [
                'conflicts' => [$this->samplePayload($attackerRecordUuid, now()->toIso8601String())],
            ])->assertOk();

        $this->assertDatabaseHas('local_sync_conflicts', [
            'record_uuid' => $attackerRecordUuid,
            'business_id' => 'tenant-local-conflict-attacker',
        ]);
        $this->assertDatabaseMissing('local_sync_conflicts', [
            'record_uuid' => $attackerRecordUuid,
            'business_id' => $victimTenant,
        ]);
    }

    public function test_retry_submission_of_the_same_conflict_does_not_duplicate(): void
    {
        $tenantId = 'tenant-local-conflict-retry';
        $token = $this->actingDeviceToken($tenantId);
        $recordUuid = (string) Str::uuid();
        $occurredAt = now()->toIso8601String();
        $payload = ['conflicts' => [$this->samplePayload($recordUuid, $occurredAt)]];

        // Simulates the client retrying because it never received the first
        // response (lost connection right after the server accepted it).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/local-conflicts', $payload)->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/local-conflicts', $payload)->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/local-conflicts', $payload)->assertOk();

        $this->assertEquals(
            1,
            LocalSyncConflict::where('record_uuid', $recordUuid)->count(),
            'Retried submission of the same conflict must not create duplicate rows.'
        );
    }
}
