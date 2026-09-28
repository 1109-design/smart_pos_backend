<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Field-level auto-merge for version conflicts (sync audit gap: "different
 * fields changed on different devices" collided as one whole-record
 * conflict, even though both edits could safely survive).
 *
 * The merge only fires when BOTH the losing and winning pushes carry an
 * explicit `_dirty_fields` marker naming which fields they actually
 * intended to change — see SyncController::attemptFieldLevelMerge() for why
 * a blind diff of the two full-row snapshots isn't safe. No current
 * Flutter code sends `_dirty_fields` yet, so these tests exercise the
 * server-side contract directly to prove it's ready for that client change.
 */
class SyncFieldLevelMergeTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-field-merge';

    private function makeDevice(string $name): string
    {
        $user = User::factory()->create(['email' => strtolower($name).'-'.Str::random(6).'@example.com']);
        $plain = $user->createToken($name)->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $this->tenantId,
            'name' => $name,
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::create([
            'id' => $this->tenantId,
            'business_name' => 'Field Merge Test Biz',
            'owner_email' => $this->tenantId.'@example.com',
        ]);
    }

    private function basePayload(): array
    {
        return [
            'name' => 'Jane Customer',
            'phone' => '0770000000',
            'email' => null,
            'address' => 'Bulawayo',
            'photo_path' => null,
            'loyalty_points' => 0,
            'credit_balance' => 0,
            'credit_limit' => 500,
            'is_tax_exempt' => false,
            'group' => 'regular',
        ];
    }

    public function test_disjoint_field_edits_from_two_devices_both_survive_via_auto_merge(): void
    {
        $tillA = $this->makeDevice('Till A');
        $tillB = $this->makeDevice('Till B');
        $customerId = (string) Str::uuid();

        Customer::create(array_merge(['id' => $customerId, 'business_id' => $this->tenantId], $this->basePayload()));

        // Till B (online first) changes the address only.
        $bPush = $this->withHeader('Authorization', 'Bearer '.$tillB)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'customers',
                    'uuid' => $customerId,
                    'operation' => 'upsert',
                    'payload' => array_merge($this->basePayload(), [
                        'business_id' => $this->tenantId,
                        'address' => 'Harare',
                        '_dirty_fields' => ['address'],
                    ]),
                    'updated_at' => now()->addMinutes(10)->toIso8601String(),
                ]],
            ]);
        $bPush->assertOk();
        $bPush->assertJsonCount(1, 'accepted');

        // Till A was offline before B's edit — it only ever touched phone,
        // but its full-row snapshot still carries A's own stale address.
        $aPush = $this->withHeader('Authorization', 'Bearer '.$tillA)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'customers',
                    'uuid' => $customerId,
                    'operation' => 'upsert',
                    'payload' => array_merge($this->basePayload(), [
                        'business_id' => $this->tenantId,
                        'phone' => '0771111111',
                        // Stale — A never saw B's address change.
                        'address' => 'Bulawayo',
                        '_dirty_fields' => ['phone'],
                    ]),
                    'updated_at' => now()->addMinutes(5)->toIso8601String(),
                ]],
            ]);

        $aPush->assertOk();
        $aPush->assertJsonCount(0, 'conflicts');
        $aPush->assertJsonCount(1, 'auto_resolved');
        $aPush->assertJsonCount(0, 'accepted');

        $this->assertDatabaseHas('sync_conflicts', [
            'business_id' => $this->tenantId,
            'table_name' => 'customers',
            'record_uuid' => $customerId,
            'status' => 'resolved',
            'conflict_type' => 'version_conflict',
            'resolution_action' => 'merged',
        ]);

        $customer = Customer::findOrFail($customerId);
        $this->assertSame('0771111111', $customer->phone, 'Phone edit from the losing push should still apply.');
        $this->assertSame('Harare', $customer->address, 'Address edit from the winning push must not be clobbered.');
    }

    public function test_same_field_edits_from_two_devices_still_collide_as_a_pending_conflict(): void
    {
        $tillA = $this->makeDevice('Till A');
        $tillB = $this->makeDevice('Till B');
        $customerId = (string) Str::uuid();

        Customer::create(array_merge(['id' => $customerId, 'business_id' => $this->tenantId], $this->basePayload()));

        $this->withHeader('Authorization', 'Bearer '.$tillB)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'customers',
                    'uuid' => $customerId,
                    'operation' => 'upsert',
                    'payload' => array_merge($this->basePayload(), [
                        'business_id' => $this->tenantId,
                        'phone' => '0772222222',
                        '_dirty_fields' => ['phone'],
                    ]),
                    'updated_at' => now()->addMinutes(10)->toIso8601String(),
                ]],
            ])->assertOk();

        $aPush = $this->withHeader('Authorization', 'Bearer '.$tillA)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'customers',
                    'uuid' => $customerId,
                    'operation' => 'upsert',
                    'payload' => array_merge($this->basePayload(), [
                        'business_id' => $this->tenantId,
                        'phone' => '0771111111',
                        '_dirty_fields' => ['phone'],
                    ]),
                    'updated_at' => now()->addMinutes(5)->toIso8601String(),
                ]],
            ]);

        $aPush->assertOk();
        $aPush->assertJsonCount(1, 'conflicts');
        $aPush->assertJsonCount(0, 'auto_resolved');

        $this->assertDatabaseHas('sync_conflicts', [
            'business_id' => $this->tenantId,
            'table_name' => 'customers',
            'record_uuid' => $customerId,
            'status' => 'pending',
            'conflict_type' => 'version_conflict',
        ]);

        // The losing push must not have been applied at all — B's value stands.
        $this->assertSame('0772222222', Customer::findOrFail($customerId)->phone);
    }

    public function test_financial_tables_never_auto_merge_even_with_dirty_fields_present(): void
    {
        $tillA = $this->makeDevice('Till A');
        $tillB = $this->makeDevice('Till B');
        $txId = (string) Str::uuid();

        $basePayload = fn (array $overrides) => array_merge([
            'business_id' => $this->tenantId,
            'user_id' => (string) Str::uuid(),
            'subtotal' => 10,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 10,
            'base_currency' => 'USD',
            'status' => 'completed',
        ], $overrides);

        $this->withHeader('Authorization', 'Bearer '.$tillB)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'transactions',
                    'uuid' => $txId,
                    'operation' => 'upsert',
                    'payload' => $basePayload(['total' => 12, '_dirty_fields' => ['total']]),
                    'updated_at' => now()->addMinutes(10)->toIso8601String(),
                ]],
            ])->assertOk();

        // Even though this push marks a disjoint dirty field ('discount_total'
        // vs B's 'total'), transactions is not in FIELD_LEVEL_MERGE_TABLES —
        // financial data must never be silently auto-merged.
        $aPush = $this->withHeader('Authorization', 'Bearer '.$tillA)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'transactions',
                    'uuid' => $txId,
                    'operation' => 'upsert',
                    'payload' => $basePayload(['discount_total' => 2, '_dirty_fields' => ['discount_total']]),
                    'updated_at' => now()->addMinutes(5)->toIso8601String(),
                ]],
            ]);

        $aPush->assertOk();
        $aPush->assertJsonCount(1, 'conflicts');
        $aPush->assertJsonCount(0, 'auto_resolved');

        $this->assertDatabaseHas('sync_conflicts', [
            'business_id' => $this->tenantId,
            'table_name' => 'transactions',
            'record_uuid' => $txId,
            'status' => 'pending',
            'conflict_type' => 'version_conflict',
        ]);

        $this->assertSame(12.0, (float) Transaction::findOrFail($txId)->total);
    }
}
