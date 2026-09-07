<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\ApprovalRequest;
use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end coverage through the real /api/v1/sync/push endpoint a till
 * actually calls — not just SalePostingService in isolation — proving the
 * SyncProcessor hooks (added in Phase 11b) actually fire and post a journal
 * from a genuine device sync push, arriving in a realistic, split-up order:
 * transaction header first, then items, then payment last.
 */
class SyncTriggersSalePostingTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);

        $user = User::factory()->create(['email' => $tenantId.'-owner@example.com']);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_a_realistic_split_sync_push_ends_with_a_posted_sale_journal(): void
    {
        $tenantId = 'tenant-e2e-sale';
        $token = $this->actingDeviceToken($tenantId);
        $txId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $push = fn (array $record) => $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [$record]])
            ->assertOk();

        // 1. The transaction header lands first — items/payment don't exist
        // yet, so this alone must not post anything.
        $push([
            'table' => 'transactions',
            'uuid' => $txId,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $tenantId,
                'user_id' => $userId,
                'subtotal' => 40,
                'tax_total' => 0,
                'discount_total' => 0,
                'total' => 40,
                'base_currency' => 'USD',
                'status' => 'completed',
                'created_at' => '2026-06-10T12:00:00Z',
            ],
            'updated_at' => now()->toIso8601String(),
        ]);
        $this->assertNull(JournalHeader::where('source_id', $txId)->first());

        // 2. The line item lands next — still no payment, still nothing posted.
        $push([
            'table' => 'transaction_items',
            'uuid' => (string) Str::uuid(),
            'operation' => 'upsert',
            'payload' => [
                'transaction_id' => $txId,
                'product_id' => (string) Str::uuid(),
                'product_name' => 'Widget',
                'quantity' => 1,
                'unit_price' => 40,
                'line_total' => 40,
            ],
            'updated_at' => now()->toIso8601String(),
        ]);
        $this->assertNull(JournalHeader::where('source_id', $txId)->first());

        // 3. The payment lands last — now the sale has everything it needs,
        // and the payments-case hook should trigger the post immediately.
        $push([
            'table' => 'payments',
            'uuid' => (string) Str::uuid(),
            'operation' => 'upsert',
            'payload' => [
                'transaction_id' => $txId,
                'method' => 'Cash',
                'amount' => 40,
                'currency_code' => 'USD',
                'base_equivalent' => 40,
            ],
            'updated_at' => now()->toIso8601String(),
        ]);

        $journal = JournalHeader::where('source_type', 'sale')->where('source_id', $txId)->first();
        $this->assertNotNull($journal, 'the sale should post as soon as its last missing piece syncs');
        $this->assertSame('posted', $journal->status);
        $this->assertSame('2026-06-10', $journal->trans_date->toDateString());

        $cash = GlAccount::where('business_id', $tenantId)->where('code', '1000')->first();
        $revenue = GlAccount::where('business_id', $tenantId)->where('code', '4000')->first();
        $this->assertSame(40.0, $cash->balance());
        $this->assertSame(40.0, $revenue->balance());
    }

    public function test_voiding_through_the_sync_endpoint_reverses_the_posted_journal(): void
    {
        $tenantId = 'tenant-e2e-void';
        $token = $this->actingDeviceToken($tenantId);
        $txId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $push = fn (array $record) => $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [$record]])
            ->assertOk();

        $base = [
            'business_id' => $tenantId,
            'user_id' => $userId,
            'subtotal' => 40,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 40,
            'base_currency' => 'USD',
            'created_at' => '2026-06-10T12:00:00Z',
        ];

        $push(['table' => 'transactions', 'uuid' => $txId, 'operation' => 'upsert', 'payload' => $base + ['status' => 'completed'], 'updated_at' => now()->toIso8601String()]);
        $push(['table' => 'transaction_items', 'uuid' => (string) Str::uuid(), 'operation' => 'upsert', 'payload' => [
            'transaction_id' => $txId, 'product_id' => (string) Str::uuid(), 'product_name' => 'Widget', 'quantity' => 1, 'unit_price' => 40, 'line_total' => 40,
        ], 'updated_at' => now()->toIso8601String()]);
        $push(['table' => 'payments', 'uuid' => (string) Str::uuid(), 'operation' => 'upsert', 'payload' => [
            'transaction_id' => $txId, 'method' => 'Cash', 'amount' => 40, 'currency_code' => 'USD', 'base_equivalent' => 40,
        ], 'updated_at' => now()->toIso8601String()]);

        $cash = GlAccount::where('business_id', $tenantId)->where('code', '1000')->first();
        $this->assertSame(40.0, $cash->balance());

        // A void must be backed by an approved approval_requests row before
        // the server will accept it — see SyncProcessor::hasApprovedRequest().
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

        // The till voids the sale and re-syncs the full transaction snapshot.
        $push(['table' => 'transactions', 'uuid' => $txId, 'operation' => 'upsert', 'payload' => $base + ['status' => 'voided'], 'updated_at' => now()->toIso8601String()]);

        $this->assertSame(0.0, $cash->fresh()->balance());
        $this->assertSame('voided', Transaction::find($txId)->status);
        $journal = JournalHeader::where('source_type', 'sale')->where('source_id', $txId)->first();
        $this->assertSame('reversed', $journal->status);
    }
}
