<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cash-tender rounding (nearest coin denomination) is folded into a
 * payment's base_equivalent by the till, but rounding_adjustment is kept as
 * its own explicit field so it can't be confused with the pre-existing
 * "owed change" case (ChangeOwedLedger), which also makes base_equivalent
 * diverge from the transaction total for an unrelated reason.
 */
class SyncPaymentRoundingAdjustmentTest extends TestCase
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

    public function test_payment_rounding_adjustment_persists_via_sync_push(): void
    {
        $tenantId = 'tenant-payment-rounding';
        $token = $this->actingDeviceToken($tenantId);
        $userId = (string) Str::uuid();
        $txId = (string) Str::uuid();
        $payId = (string) Str::uuid();

        Transaction::create([
            'id' => $txId,
            'business_id' => $tenantId,
            'user_id' => $userId,
            'subtotal' => 19.97,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 19.97,
            'base_currency' => 'USD',
            'status' => 'completed',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'payments',
                    'uuid' => $payId,
                    'operation' => 'upsert',
                    'payload' => [
                        'transaction_id' => $txId,
                        'method' => 'Cash',
                        'amount' => 20.00,
                        'currency_code' => 'USD',
                        'exchange_rate_used' => 1,
                        'base_equivalent' => 20.00,
                        'change_given' => 0,
                        'rounding_adjustment' => 0.03,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');

        $payment = Payment::findOrFail($payId);
        $this->assertSame('0.0300', $payment->rounding_adjustment);
        $this->assertSame('20.0000', $payment->base_equivalent);
    }

    public function test_payment_rounding_adjustment_defaults_to_zero_when_absent(): void
    {
        $tenantId = 'tenant-payment-no-rounding';
        $token = $this->actingDeviceToken($tenantId);
        $userId = (string) Str::uuid();
        $txId = (string) Str::uuid();
        $payId = (string) Str::uuid();

        Transaction::create([
            'id' => $txId,
            'business_id' => $tenantId,
            'user_id' => $userId,
            'subtotal' => 10.00,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 10.00,
            'base_currency' => 'USD',
            'status' => 'completed',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'payments',
                    'uuid' => $payId,
                    'operation' => 'upsert',
                    'payload' => [
                        'transaction_id' => $txId,
                        'method' => 'Card',
                        'amount' => 10.00,
                        'currency_code' => 'USD',
                        'exchange_rate_used' => 1,
                        'base_equivalent' => 10.00,
                        'change_given' => 0,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();

        $payment = Payment::findOrFail($payId);
        $this->assertSame('0.0000', $payment->rounding_adjustment);
    }
}
