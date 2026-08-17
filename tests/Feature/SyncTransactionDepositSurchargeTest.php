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
 * Regression coverage for a mass-assignment gap: Transaction::$fillable was
 * missing deposit_total (and, until now, surcharge_total didn't exist), so
 * SyncProcessor's updateOrCreate() silently dropped both — the server always
 * persisted 0 regardless of what the device sent.
 */
class SyncTransactionDepositSurchargeTest extends TestCase
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

    public function test_transaction_deposit_and_surcharge_totals_persist_via_sync_push(): void
    {
        $tenantId = 'tenant-tx-deposit-surcharge';
        $token = $this->actingDeviceToken($tenantId);
        $userId = (string) Str::uuid();
        $txId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'transactions',
                    'uuid' => $txId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'user_id' => $userId,
                        'subtotal' => 10.00,
                        'tax_total' => 0,
                        'discount_total' => 0,
                        'deposit_total' => 0.20,
                        'surcharge_total' => 0.10,
                        'total' => 10.30,
                        'base_currency' => 'USD',
                        'status' => 'completed',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');

        $tx = Transaction::findOrFail($txId);
        $this->assertSame('0.2000', $tx->deposit_total);
        $this->assertSame('0.1000', $tx->surcharge_total);
    }
}
