<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the two Part A "cheapest wins" changes: a generic approval_requests
 * table (Phase 0) and the void_reason column on transactions (Phase 1a) —
 * both must round-trip through the real device sync API, the same path
 * TransferService/StockTransfer already prove out for stock_transfers.
 */
class SyncApprovalRequestAndVoidReasonTest extends TestCase
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

    public function test_approval_request_round_trips_through_push_and_pull(): void
    {
        $tenantId = 'tenant-sync-approval-1';
        $token = $this->actingDeviceToken($tenantId);
        $requestId = (string) Str::uuid();
        $subjectId = (string) Str::uuid();
        $requestedBy = (string) Str::uuid();

        $push = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'approval_requests',
                    'uuid' => $requestId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'subject_type' => 'Transaction',
                        'subject_id' => $subjectId,
                        'action' => 'void_transaction',
                        'requested_by_user_id' => $requestedBy,
                        'status' => 'pending',
                        'payload_json' => ['sale_number' => 'SALE-0001'],
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $push->assertOk();
        $push->assertJsonCount(1, 'accepted');

        $request = ApprovalRequest::findOrFail($requestId);
        $this->assertSame('pending', $request->status);
        $this->assertSame('void_transaction', $request->action);
        $this->assertSame('SALE-0001', $request->payload_json['sale_number']);

        // A different device on the same business must see it on pull.
        $otherToken = $this->actingDeviceToken($tenantId.'-other-owner');
        Device::where('tenant_id', $tenantId.'-other-owner')->update(['tenant_id' => $tenantId]);

        $pull = $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->getJson('/api/v1/sync/pull?'.http_build_query(['tables' => ['approval_requests']]));

        $pull->assertOk();
        $this->assertContains($requestId, collect($pull->json('records'))->pluck('record_uuid')->all());
    }

    public function test_approval_request_status_transitions_via_a_second_upsert(): void
    {
        $tenantId = 'tenant-sync-approval-2';
        $token = $this->actingDeviceToken($tenantId);
        $requestId = (string) Str::uuid();
        $approverId = (string) Str::uuid();

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', [
            'records' => [[
                'table' => 'approval_requests',
                'uuid' => $requestId,
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'subject_type' => 'ExchangeRate',
                    'subject_id' => (string) Str::uuid(),
                    'action' => 'change_exchange_rate',
                    'requested_by_user_id' => (string) Str::uuid(),
                    'status' => 'pending',
                ],
                'updated_at' => now()->toIso8601String(),
            ]],
        ])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', [
            'records' => [[
                'table' => 'approval_requests',
                'uuid' => $requestId,
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'subject_type' => 'ExchangeRate',
                    'subject_id' => ApprovalRequest::find($requestId)->subject_id,
                    'action' => 'change_exchange_rate',
                    'requested_by_user_id' => ApprovalRequest::find($requestId)->requested_by_user_id,
                    'status' => 'approved',
                    'approver_user_id' => $approverId,
                    'approved_at' => now()->toIso8601String(),
                ],
                'updated_at' => now()->toIso8601String(),
            ]],
        ])->assertOk();

        $request = ApprovalRequest::findOrFail($requestId);
        $this->assertSame('approved', $request->status);
        $this->assertSame($approverId, $request->approver_user_id);
        $this->assertNotNull($request->approved_at);
    }

    public function test_void_reason_persists_via_sync_push(): void
    {
        $tenantId = 'tenant-sync-void-1';
        $token = $this->actingDeviceToken($tenantId);
        $txId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        Transaction::create([
            'id' => $txId,
            'business_id' => $tenantId,
            'user_id' => $userId,
            'subtotal' => 10,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 10,
            'base_currency' => 'USD',
            'status' => 'completed',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'transactions',
                    'uuid' => $txId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'user_id' => $userId,
                        'subtotal' => 10,
                        'tax_total' => 0,
                        'discount_total' => 0,
                        'total' => 10,
                        'base_currency' => 'USD',
                        'status' => 'voided',
                        'void_reason' => 'Customer changed their mind',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();

        $transaction = Transaction::findOrFail($txId);
        $this->assertSame('voided', $transaction->status);
        $this->assertSame('Customer changed their mind', $transaction->void_reason);
    }
}
