<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\PendingCollection;
use App\Models\PendingCollectionEvent;
use App\Models\PendingCollectionUsedOtp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SyncPendingBookTest extends TestCase
{
    use RefreshDatabase;

    private int $clock = 0;

    private function deviceToken(string $tenantId, bool $createTenant = true): string
    {
        if ($createTenant) {
            Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        }

        $user = User::factory()->create([
            'business_id' => $tenantId,
            'email' => $tenantId.'-'.Str::random(5).'@example.com',
        ]);
        $plain = $user->createToken('sync-test')->plainTextToken;

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function push(string $token, string $table, string $uuid, array $payload, string $op = 'upsert'): TestResponse
    {
        // Strictly increasing updated_at, so the generic "older than what
        // the server holds" check never interferes with what's under test.
        $updatedAt = now()->addSeconds(++$this->clock)->toIso8601String();

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => $table,
                    'uuid' => $uuid,
                    'operation' => $op,
                    'payload' => $payload + ['updated_at' => $updatedAt],
                    'updated_at' => $updatedAt,
                ]],
            ]);
    }

    private function entry(string $tenantId, array $overrides = []): array
    {
        return $overrides + [
            'business_id' => $tenantId,
            'transaction_id' => (string) Str::uuid(),
            'location_id' => null,
            'collector_name' => 'Tendai',
            'collector_phone' => '+263771111111',
            'status' => 'awaiting_stock',
            'items_json' => '[{"productId":"p1","quantityPending":10,"deferredStock":true,"quantityAllocated":0}]',
            'otp_code' => null,
            'version' => 1,
            'base_version' => 0,
            'device_id' => 'device-A',
            'created_at' => now()->toIso8601String(),
        ];
    }

    public function test_entry_is_created_then_updated_on_the_version_it_was_based_on(): void
    {
        $token = $this->deviceToken('t-pb-1');
        $id = (string) Str::uuid();

        $this->push($token, 'pending_collections', $id, $this->entry('t-pb-1'))
            ->assertOk()->assertJsonCount(1, 'accepted');
        $this->push($token, 'pending_collections', $id, $this->entry('t-pb-1', [
            'status' => 'otp_sent', 'otp_code' => '482913', 'version' => 2, 'base_version' => 1,
        ]))->assertOk()->assertJsonCount(1, 'accepted');

        $row = PendingCollection::findOrFail($id);
        $this->assertSame('otp_sent', $row->status);
        $this->assertSame(2, $row->version);
        $this->assertSame('device-A', $row->device_id);
    }

    public function test_second_offline_confirmation_of_the_same_entry_is_refused(): void
    {
        $token = $this->deviceToken('t-pb-2');
        $id = (string) Str::uuid();
        $this->push($token, 'pending_collections', $id, $this->entry('t-pb-2', [
            'status' => 'otp_sent', 'otp_code' => '111111', 'version' => 3, 'base_version' => 0,
        ]))->assertOk();

        // Till A confirms (built on v3) → v4, accepted.
        $this->push($token, 'pending_collections', $id, $this->entry('t-pb-2', [
            'status' => 'collected', 'version' => 4, 'base_version' => 3,
        ]))->assertJsonCount(1, 'accepted');

        // Till B, offline, confirmed the same code on top of v3 too.
        $this->push($token, 'pending_collections', $id, $this->entry('t-pb-2', [
            'status' => 'partially_collected', 'otp_code' => '222222', 'version' => 4, 'base_version' => 3,
        ]))->assertJsonCount(0, 'accepted')
            // Refused and recorded as a processing_error conflict for review.
            ->assertJsonCount(1, 'errors')
            ->assertJsonPath('errors.0.table', 'pending_collections');

        $this->assertSame('collected', PendingCollection::findOrFail($id)->status);
    }

    public function test_resent_push_is_idempotent(): void
    {
        $token = $this->deviceToken('t-pb-3');
        $id = (string) Str::uuid();
        $payload = $this->entry('t-pb-3');

        $this->push($token, 'pending_collections', $id, $payload)->assertJsonCount(1, 'accepted');
        $this->push($token, 'pending_collections', $id, $payload)->assertJsonCount(1, 'accepted');
        $this->assertSame(1, PendingCollection::findOrFail($id)->version);
    }

    public function test_cancelled_entry_never_reopens(): void
    {
        $token = $this->deviceToken('t-pb-4');
        $id = (string) Str::uuid();
        $this->push($token, 'pending_collections', $id, $this->entry('t-pb-4', ['status' => 'cancelled']));

        $this->push($token, 'pending_collections', $id, $this->entry('t-pb-4', [
            'status' => 'otp_sent', 'version' => 2, 'base_version' => 1,
        ]))->assertJsonCount(0, 'accepted');

        $this->assertSame('cancelled', PendingCollection::findOrFail($id)->status);
    }

    public function test_otp_is_never_issued_to_two_entries(): void
    {
        $token = $this->deviceToken('t-pb-5');
        $first = (string) Str::uuid();

        $otp = fn (string $collectionId) => [
            'otp' => '654321', 'collection_id' => $collectionId,
            'business_id' => 't-pb-5', 'issued_at' => now()->toIso8601String(),
        ];

        $this->push($token, 'pending_collection_used_otps', 't-pb-5:654321', $otp($first))
            ->assertJsonCount(1, 'accepted');
        // Same code for the same entry again — a harmless re-send.
        $this->push($token, 'pending_collection_used_otps', 't-pb-5:654321', $otp($first))
            ->assertJsonCount(1, 'accepted');
        // Same code drawn by another till for a different entry — refused.
        $this->push($token, 'pending_collection_used_otps', 't-pb-5:654321', $otp((string) Str::uuid()))
            ->assertJsonCount(0, 'accepted');

        $this->assertSame($first, PendingCollectionUsedOtp::where('otp', '654321')->sole()->collection_id);
    }

    public function test_events_are_append_only_and_deletes_are_ignored(): void
    {
        $token = $this->deviceToken('t-pb-6');
        $id = (string) Str::uuid();
        $this->push($token, 'pending_collections', $id, $this->entry('t-pb-6'));

        $event = fn (string $type) => [
            'collection_id' => $id, 'business_id' => 't-pb-6', 'event_type' => $type,
            'data_json' => '{}', 'created_at' => now()->toIso8601String(),
        ];
        $this->push($token, 'pending_collection_events', 'reversal:abc', $event('reversal_approved'))
            ->assertJsonCount(1, 'accepted');
        $this->push($token, 'pending_collection_events', 'reversal:abc', $event('reversal_rejected'))
            ->assertOk();
        $this->assertSame('reversal_approved', PendingCollectionEvent::findOrFail('reversal:abc')->event_type);

        $this->push($token, 'pending_collection_events', 'reversal:abc', [], 'delete')->assertOk();
        $this->push($token, 'pending_collections', $id, [], 'delete')->assertOk();
        $this->assertNotNull(PendingCollectionEvent::find('reversal:abc'));
        $this->assertNotNull(PendingCollection::find($id));
    }

    public function test_event_for_another_tenants_entry_is_refused(): void
    {
        $other = $this->deviceToken('t-pb-other');
        $foreign = (string) Str::uuid();
        $this->push($other, 'pending_collections', $foreign, $this->entry('t-pb-other'));

        $token = $this->deviceToken('t-pb-7');
        $this->push($token, 'pending_collection_events', (string) Str::uuid(), [
            'collection_id' => $foreign, 'business_id' => 't-pb-7', 'event_type' => 'created',
            'data_json' => '{}', 'created_at' => now()->toIso8601String(),
        ])->assertJsonCount(0, 'accepted');
    }
}
