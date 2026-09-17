<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\SyncConflict;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sync audit gap: handleDelete() and the ordinary upsert path used to share
 * the same generic timestamp-based version-conflict check, so a later-
 * arriving edit (plausible under clock skew — an offline device's local
 * clock can easily run ahead) could resurrect a record another device had
 * already deleted, with no flag distinguishing the resurrection from a
 * record that was never deleted.
 *
 * Fixed behavior: SyncController::push() now checks whether the most recent
 * sync_records row for a uuid is itself a 'delete' before applying any
 * incoming upsert, REGARDLESS of the incoming payload's claimed timestamp.
 * If so, the edit is routed to sync_conflicts (conflict_type
 * 'edit_after_delete', status 'pending') instead of being applied — deletes
 * are Category C in the audit's conflict classification: always require
 * human review, never silently resurrected OR silently discarded.
 */
class SyncTombstonePrecedenceTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId, string $deviceName = 'Test Device'): string
    {
        if (! Tenant::where('id', $tenantId)->exists()) {
            Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        }

        $user = User::factory()->create(['email' => $tenantId.'-'.Str::slug($deviceName).'@example.com']);
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

    public function test_a_later_clocked_edit_cannot_resurrect_a_customer_deleted_on_another_device(): void
    {
        $tenantId = 'tenant-tombstone-1';
        $deviceA = $this->actingDeviceToken($tenantId, 'Till A');
        $deviceB = $this->actingDeviceToken($tenantId, 'Till B');

        $customerId = (string) Str::uuid();
        Customer::create(['id' => $customerId, 'business_id' => $tenantId, 'name' => 'Original Name', 'phone' => '0771234567']);

        // Device A deletes the customer — this actually happened FIRST in
        // real time (an earlier server-received timestamp), even though its
        // own payload timestamp is deliberately earlier than B's below.
        $deleteResp = $this->withHeader('Authorization', 'Bearer '.$deviceA)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'customers',
                    'uuid' => $customerId,
                    'operation' => 'delete',
                    'payload' => ['business_id' => $tenantId],
                    'updated_at' => now()->subMinutes(5)->toIso8601String(),
                ]],
            ]);
        $deleteResp->assertOk();
        $this->assertCount(1, $deleteResp->json('accepted'));
        $this->assertDatabaseMissing('customers', ['id' => $customerId]);

        // Device B, offline this whole time with a clock running fast,
        // pushes an edit to the SAME customer with a timestamp that looks
        // newer than the delete — this is exactly the clock-skew scenario
        // the fix must not trust.
        $editResp = $this->withHeader('Authorization', 'Bearer '.$deviceB)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'customers',
                    'uuid' => $customerId,
                    'operation' => 'upsert',
                    'payload' => ['business_id' => $tenantId, 'name' => 'Original Name', 'phone' => '0779999999'],
                    'updated_at' => now()->addMinutes(30)->toIso8601String(),
                ]],
            ]);

        $editResp->assertOk();
        $this->assertCount(0, $editResp->json('accepted'), 'the edit must not be silently applied');
        $this->assertCount(1, $editResp->json('conflicts'), 'it must surface as a conflict requiring review, not succeed or silently vanish');
        $this->assertCount(0, $editResp->json('errors'));

        // The customer must stay deleted — no resurrection.
        $this->assertDatabaseMissing('customers', ['id' => $customerId]);

        $conflict = SyncConflict::where('business_id', $tenantId)
            ->where('table_name', 'customers')
            ->where('record_uuid', $customerId)
            ->first();

        $this->assertNotNull($conflict);
        $this->assertSame('edit_after_delete', $conflict->conflict_type);
        $this->assertSame('pending', $conflict->status);
        $this->assertSame('0779999999', $conflict->local_payload['phone'] ?? null, 'the attempted edit must be preserved for review, not lost');
    }

    public function test_a_second_delete_for_an_already_deleted_record_is_a_harmless_no_op(): void
    {
        $tenantId = 'tenant-tombstone-2';
        $token = $this->actingDeviceToken($tenantId);

        $customerId = (string) Str::uuid();
        Customer::create(['id' => $customerId, 'business_id' => $tenantId, 'name' => 'Double Delete Customer']);

        $push = fn () => $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'customers',
                    'uuid' => $customerId,
                    'operation' => 'delete',
                    'payload' => ['business_id' => $tenantId],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $push()->assertOk();
        $this->assertDatabaseMissing('customers', ['id' => $customerId]);

        // A second delete for the same, already-deleted uuid (e.g. two
        // devices both deleted it offline) must not be treated as an
        // edit-after-delete conflict — deletes are idempotent with
        // themselves.
        $resp = $push();
        $resp->assertOk();
        $this->assertCount(1, $resp->json('accepted'));
        $this->assertCount(0, $resp->json('conflicts'));
    }

    public function test_an_edit_that_arrives_before_any_delete_is_applied_normally(): void
    {
        $tenantId = 'tenant-tombstone-3';
        $token = $this->actingDeviceToken($tenantId);

        $customerId = (string) Str::uuid();
        Customer::create(['id' => $customerId, 'business_id' => $tenantId, 'name' => 'Not Deleted Yet']);

        // Regression guard: no prior delete exists for this uuid, so a plain
        // edit must go through exactly as before this fix.
        $resp = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'customers',
                    'uuid' => $customerId,
                    'operation' => 'upsert',
                    'payload' => ['business_id' => $tenantId, 'name' => 'Renamed'],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $resp->assertOk();
        $this->assertCount(1, $resp->json('accepted'));
        $this->assertCount(0, $resp->json('conflicts'));
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'name' => 'Renamed']);
    }
}
