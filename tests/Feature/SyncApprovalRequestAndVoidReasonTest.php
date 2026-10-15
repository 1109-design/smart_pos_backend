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
        Device::where('tenant_id', $tenantId.'-other-owner')->get()->each->update(['tenant_id' => $tenantId]);

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

    /**
     * Regression test for the Flutter Approvals crash ("type 'List<dynamic>'
     * is not a subtype of type 'Map<String, dynamic>'"): PHP has no distinct
     * "empty map" type, so an approval request's payload_json that started
     * life as an empty JSON *object* ('{}') gets flattened by json_decode
     * into an empty PHP array indistinguishable from an empty JSON *array*
     * ('[]') — and every plain PHP array re-encodes as '[]', never '{}'.
     * Every client reader of payload_json decodes it expecting an object
     * and crashes on an array, so this must round-trip through pull as '{}'.
     */
    public function test_approval_request_with_empty_payload_pulls_back_as_a_json_object_not_an_array(): void
    {
        $tenantId = 'tenant-sync-approval-empty-payload';
        $token = $this->actingDeviceToken($tenantId);
        $requestId = (string) Str::uuid();

        // (object) [] mirrors exactly what a Flutter device sends for an
        // empty payload map — jsonEncode(<String, dynamic>{}) always
        // produces the JSON object '{}', never '[]'. A plain PHP []
        // here would send '[]' in the request body instead, missing the
        // actual bug this test exists to catch.
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', [
            'records' => [[
                'table' => 'approval_requests',
                'uuid' => $requestId,
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'subject_type' => 'Transaction',
                    'subject_id' => (string) Str::uuid(),
                    'action' => 'void_transaction',
                    'requested_by_user_id' => (string) Str::uuid(),
                    'status' => 'pending',
                    'payload_json' => (object) [],
                ],
                'updated_at' => now()->toIso8601String(),
            ]],
        ])->assertOk();

        $otherToken = $this->actingDeviceToken($tenantId.'-other-owner');
        Device::where('tenant_id', $tenantId.'-other-owner')->get()->each->update(['tenant_id' => $tenantId]);

        $pull = $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->getJson('/api/v1/sync/pull?'.http_build_query(['tables' => ['approval_requests']]));

        $pull->assertOk();
        $record = collect($pull->json('records'))->firstWhere('record_uuid', $requestId);
        $this->assertNotNull($record);

        // json_decode() on the response here would silently paper over an
        // object-vs-array mismatch by decoding both shapes identically —
        // exactly the bug this test exists to catch — so this asserts
        // against the literal wire bytes instead.
        $this->assertStringContainsString(
            '"payload_json":{}',
            $pull->getContent(),
            'payload_json must serialize as a JSON object, not an array, for an empty payload — Flutter clients crash decoding it as List<dynamic>.',
        );
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

        ApprovalRequest::create([
            'business_id' => $tenantId,
            'subject_type' => 'Transaction',
            'subject_id' => $txId,
            'action' => 'void_transaction',
            'requested_by_user_id' => $userId,
            'status' => 'approved',
            'approver_user_id' => $userId,
            'approved_at' => now(),
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

    public function test_void_is_accepted_even_before_its_approval_reaches_the_server(): void
    {
        $tenantId = 'tenant-sync-void-2';
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

        // No ApprovalRequest seeded: the app decides voids, and its approval
        // row can reach the server after the void itself. The server relays
        // the void rather than re-checking it.
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
                        'void_reason' => 'no approval behind this',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertEmpty($response->json('errors'));
        $this->assertSame('voided', Transaction::findOrFail($txId)->status);
    }

    public function test_refund_compensating_transaction_is_accepted_even_before_its_approval_reaches_the_server(): void
    {
        $tenantId = 'tenant-sync-refund-1';
        $token = $this->actingDeviceToken($tenantId);
        $originalTxId = (string) Str::uuid();
        $refundTxId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        Transaction::create([
            'id' => $originalTxId,
            'business_id' => $tenantId,
            'user_id' => $userId,
            'subtotal' => 10,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 10,
            'base_currency' => 'USD',
            'status' => 'completed',
        ]);

        // Regression: on 2026-10-09 refunds pushed in the same second as
        // their approval were rejected by a server-side approval check and
        // left behind as pending conflicts. The app decides refunds; the
        // server relays the compensating row.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'transactions',
                    'uuid' => $refundTxId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'user_id' => $userId,
                        'subtotal' => -10,
                        'tax_total' => 0,
                        'discount_total' => 0,
                        'total' => -10,
                        'base_currency' => 'USD',
                        'status' => 'refunded',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertEmpty($response->json('errors'));
        $this->assertDatabaseHas('transactions', ['id' => $refundTxId, 'status' => 'refunded']);
    }

    public function test_exchange_rate_change_is_accepted_even_before_its_approval_reaches_the_server(): void
    {
        $tenantId = 'tenant-sync-fx-1';
        $token = $this->actingDeviceToken($tenantId);
        $rateId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'exchange_rates',
                    'uuid' => $rateId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'from_currency' => 'USD',
                        'to_currency' => 'ZWG',
                        'rate' => 30000,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertEmpty($response->json('errors'));
        $this->assertDatabaseHas('exchange_rates', ['id' => $rateId]);
    }

    public function test_exchange_rate_change_is_accepted_with_a_matching_approved_approval_request(): void
    {
        $tenantId = 'tenant-sync-fx-2';
        $token = $this->actingDeviceToken($tenantId);
        $rateId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        ApprovalRequest::create([
            'business_id' => $tenantId,
            'subject_type' => 'ExchangeRate',
            'subject_id' => $rateId,
            'action' => 'change_exchange_rate',
            'requested_by_user_id' => $userId,
            'status' => 'approved',
            'approver_user_id' => $userId,
            'approved_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'exchange_rates',
                    'uuid' => $rateId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'from_currency' => 'USD',
                        'to_currency' => 'ZWG',
                        'rate' => 30000,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('exchange_rates', ['id' => $rateId, 'rate' => 30000]);
    }
}
