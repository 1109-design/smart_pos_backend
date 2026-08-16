<?php

namespace Tests\Feature;

use App\Models\ChangeOwedLedger;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the opt-in "owe change instead of paying it out" sync path: an
 * append-only change_owed_ledger, keyed by transaction_id (the receipt a
 * customer later presents to claim it), not by customer — most quick cash
 * sales never capture a customer at all.
 */
class SyncChangeOwedLedgerTest extends TestCase
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

    public function test_change_owed_issue_and_claim_sync_via_push(): void
    {
        $tenantId = 'tenant-change-owed-1';
        $token = $this->actingDeviceToken($tenantId);

        $txId = (string) Str::uuid();
        $issueId = (string) Str::uuid();
        $claimId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        Transaction::create([
            'id' => $txId, 'business_id' => $tenantId, 'user_id' => $userId,
            'subtotal' => 40, 'total' => 40, 'base_currency' => 'USD',
            'status' => 'completed', 'sale_number' => '202608-TEST-1',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [
                    [
                        'table' => 'change_owed_ledger',
                        'uuid' => $issueId,
                        'operation' => 'upsert',
                        'payload' => [
                            'business_id' => $tenantId,
                            'transaction_id' => $txId,
                            'amount' => 10,
                            'currency_code' => 'USD',
                            'type' => 'issue',
                            'user_id' => $userId,
                        ],
                        'updated_at' => now()->toIso8601String(),
                    ],
                    [
                        'table' => 'change_owed_ledger',
                        'uuid' => $claimId,
                        'operation' => 'upsert',
                        'payload' => [
                            'business_id' => $tenantId,
                            'transaction_id' => $txId,
                            'amount' => -6,
                            'currency_code' => 'USD',
                            'type' => 'claim',
                            'user_id' => $userId,
                        ],
                        'updated_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'accepted');

        $this->assertDatabaseHas('change_owed_ledger', [
            'id' => $issueId, 'business_id' => $tenantId, 'transaction_id' => $txId,
            'amount' => 10, 'type' => 'issue',
        ]);
        $this->assertDatabaseHas('change_owed_ledger', [
            'id' => $claimId, 'business_id' => $tenantId, 'transaction_id' => $txId,
            'amount' => -6, 'type' => 'claim',
        ]);

        $outstanding = ChangeOwedLedger::where('transaction_id', $txId)->sum('amount');
        $this->assertEquals(4, $outstanding);
    }

    public function test_change_owed_ledger_cannot_be_overwritten_by_another_business(): void
    {
        $victimTenant = 'tenant-change-owed-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $txId = (string) Str::uuid();
        Transaction::create([
            'id' => $txId, 'business_id' => $victimTenant, 'user_id' => (string) Str::uuid(),
            'subtotal' => 40, 'total' => 40, 'base_currency' => 'USD', 'status' => 'completed',
        ]);

        $ledgerId = (string) Str::uuid();
        ChangeOwedLedger::create([
            'id' => $ledgerId, 'business_id' => $victimTenant, 'transaction_id' => $txId,
            'amount' => 10, 'currency_code' => 'USD', 'type' => 'issue', 'user_id' => (string) Str::uuid(),
        ]);

        $attackerToken = $this->actingDeviceToken('tenant-change-owed-attacker');

        $response = $this->withHeader('Authorization', 'Bearer '.$attackerToken)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'change_owed_ledger',
                    'uuid' => $ledgerId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => 'tenant-change-owed-attacker',
                        'transaction_id' => $txId,
                        'amount' => -999,
                        'currency_code' => 'USD',
                        'type' => 'claim',
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'accepted');
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('change_owed_ledger', [
            'id' => $ledgerId, 'business_id' => $victimTenant, 'amount' => 10, 'type' => 'issue',
        ]);
    }

    public function test_change_owed_ledger_rows_are_immutable(): void
    {
        $tenantId = 'tenant-change-owed-immutable';
        $txId = (string) Str::uuid();
        Transaction::create([
            'id' => $txId, 'business_id' => $tenantId, 'user_id' => (string) Str::uuid(),
            'subtotal' => 40, 'total' => 40, 'base_currency' => 'USD', 'status' => 'completed',
        ]);

        $ledgerId = (string) Str::uuid();
        ChangeOwedLedger::create([
            'id' => $ledgerId, 'business_id' => $tenantId, 'transaction_id' => $txId,
            'amount' => 5, 'currency_code' => 'USD', 'type' => 'issue', 'user_id' => (string) Str::uuid(),
        ]);

        app(SyncProcessor::class)->process('change_owed_ledger', $ledgerId, 'delete', ['business_id' => $tenantId]);

        $this->assertDatabaseHas('change_owed_ledger', ['id' => $ledgerId]);
    }
}
