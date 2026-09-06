<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for a real bug found while building Phase 11b: the
 * 'transactions' case's own comment claimed to "preserve the device's sale
 * timestamp on first insert" by putting created_at into the updateOrCreate()
 * attributes array — but Eloquent's automatic timestamp management
 * overwrites created_at with "now" during insert regardless of what's in
 * that array, so every synced sale silently got the sync-arrival time
 * instead of its actual sale time. Matters beyond accounting: any report
 * that groups sales by date is wrong for a till that syncs late.
 */
class SyncTransactionCreatedAtTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create(['email' => $tenantId.'-owner@example.com']);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_a_new_transactions_created_at_is_preserved_from_the_device_not_the_sync_time(): void
    {
        $tenantId = 'tenant-tx-created-at';
        $token = $this->actingDeviceToken($tenantId);
        $txId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'transactions',
                    'uuid' => $txId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'user_id' => (string) Str::uuid(),
                        'subtotal' => 10,
                        'tax_total' => 0,
                        'discount_total' => 0,
                        'total' => 10,
                        'base_currency' => 'USD',
                        'status' => 'completed',
                        // The device was offline for a while — this sale
                        // actually happened three days before it synced.
                        'created_at' => '2026-06-01T09:00:00Z',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();

        $tx = Transaction::findOrFail($txId);
        $this->assertSame('2026-06-01', $tx->created_at->toDateString());
    }

    public function test_updating_an_existing_transaction_does_not_touch_its_original_created_at(): void
    {
        $tenantId = 'tenant-tx-created-at-update';
        $token = $this->actingDeviceToken($tenantId);
        $txId = (string) Str::uuid();

        $push = fn (array $payload) => $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'transactions',
                    'uuid' => $txId,
                    'operation' => 'upsert',
                    'payload' => $payload,
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $base = [
            'business_id' => $tenantId,
            'user_id' => (string) Str::uuid(),
            'subtotal' => 10,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 10,
            'base_currency' => 'USD',
            'created_at' => '2026-06-01T09:00:00Z',
        ];

        $push($base + ['status' => 'completed'])->assertOk();
        $originalCreatedAt = Transaction::findOrFail($txId)->created_at;

        // A later status update (e.g. void) re-sends the full snapshot,
        // including the same created_at — this must not be treated as a
        // "first insert" and re-saved a second time, and definitely must
        // not drift to "now".
        $push($base + ['status' => 'voided'])->assertOk();

        $this->assertTrue($originalCreatedAt->eq(Transaction::findOrFail($txId)->created_at));
        $this->assertSame('2026-06-01', Transaction::findOrFail($txId)->created_at->toDateString());
    }
}
