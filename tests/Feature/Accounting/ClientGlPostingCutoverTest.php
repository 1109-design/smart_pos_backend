<?php

namespace Tests\Feature\Accounting;

use App\Console\Commands\Accounting\PostPendingSales;
use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Accounting\JournalLine;
use App\Models\Business;
use App\Models\Device;
use App\Models\Payment;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1 of the Flutter-first GL posting migration — proves the
 * client_gl_posting_enabled_at cutover actually prevents the server from
 * double-posting once a business's till starts posting its own journals,
 * that a non-cutover business sees zero behavior change, that the new
 * ingest cases reject a cross-tenant gl_account reference, that the
 * cutover flag and chart of accounts both reach devices via sync, and that
 * the PostPendingSales grace-period fallback catches an old-app-version
 * straggler.
 */
class ClientGlPostingCutoverTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId, ?string $clientGlPostingEnabledAt = null): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create([
            'id' => $tenantId,
            'name' => $tenantId,
            'currency_code' => 'USD',
            'accounting_go_live_date' => '2026-01-01',
            'client_gl_posting_enabled_at' => $clientGlPostingEnabledAt,
        ]);
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

    private function pushSaleSkeleton(string $token, string $tenantId, string $txId): void
    {
        $push = fn (array $record) => $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [$record]])
            ->assertOk();

        $push(['table' => 'transactions', 'uuid' => $txId, 'operation' => 'upsert', 'payload' => [
            'business_id' => $tenantId, 'user_id' => (string) Str::uuid(), 'subtotal' => 40, 'tax_total' => 0,
            'discount_total' => 0, 'total' => 40, 'base_currency' => 'USD', 'status' => 'completed',
            'created_at' => '2026-06-10T12:00:00Z',
        ], 'updated_at' => now()->toIso8601String()]);
        $push(['table' => 'transaction_items', 'uuid' => (string) Str::uuid(), 'operation' => 'upsert', 'payload' => [
            'transaction_id' => $txId, 'product_id' => (string) Str::uuid(), 'product_name' => 'Widget',
            'quantity' => 1, 'unit_price' => 40, 'line_total' => 40,
        ], 'updated_at' => now()->toIso8601String()]);
        $push(['table' => 'payments', 'uuid' => (string) Str::uuid(), 'operation' => 'upsert', 'payload' => [
            'transaction_id' => $txId, 'method' => 'Cash', 'amount' => 40, 'currency_code' => 'USD', 'base_equivalent' => 40,
        ], 'updated_at' => now()->toIso8601String()]);
    }

    /** Pushes a fully-posted journal (header + one balanced line pair + matching general_ledger rows) as if Flutter had already computed and posted it locally. */
    private function pushClientJournal(string $token, string $tenantId, string $txId, GlAccount $debitAccount, GlAccount $creditAccount, float $amount): string
    {
        $headerId = (string) Str::uuid();
        $debitLineId = (string) Str::uuid();
        $creditLineId = (string) Str::uuid();

        $push = fn (array $record) => $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [$record]])
            ->assertOk();

        $push(['table' => 'journal_headers', 'uuid' => $headerId, 'operation' => 'upsert', 'payload' => [
            'business_id' => $tenantId, 'journal_number' => 'JNL-2026-'.substr($headerId, 0, 8),
            'trans_date' => '2026-06-10', 'source_type' => 'sale', 'source_id' => $txId, 'status' => 'posted',
        ], 'updated_at' => now()->toIso8601String()]);

        $push(['table' => 'journal_lines', 'uuid' => $debitLineId, 'operation' => 'upsert', 'payload' => [
            'journal_header_id' => $headerId, 'gl_account_id' => $debitAccount->id, 'debit' => $amount, 'credit' => 0,
        ], 'updated_at' => now()->toIso8601String()]);
        $push(['table' => 'journal_lines', 'uuid' => $creditLineId, 'operation' => 'upsert', 'payload' => [
            'journal_header_id' => $headerId, 'gl_account_id' => $creditAccount->id, 'debit' => 0, 'credit' => $amount,
        ], 'updated_at' => now()->toIso8601String()]);

        $push(['table' => 'general_ledger', 'uuid' => (string) Str::uuid(), 'operation' => 'upsert', 'payload' => [
            'business_id' => $tenantId, 'trans_date' => '2026-06-10', 'journal_header_id' => $headerId,
            'gl_account_id' => $debitAccount->id, 'debit' => $amount, 'credit' => 0, 'status' => 'active',
        ], 'updated_at' => now()->toIso8601String()]);
        $push(['table' => 'general_ledger', 'uuid' => (string) Str::uuid(), 'operation' => 'upsert', 'payload' => [
            'business_id' => $tenantId, 'trans_date' => '2026-06-10', 'journal_header_id' => $headerId,
            'gl_account_id' => $creditAccount->id, 'debit' => 0, 'credit' => $amount, 'status' => 'active',
        ], 'updated_at' => now()->toIso8601String()]);

        return $headerId;
    }

    public function test_a_cutover_business_gets_no_server_posted_journal_for_a_plain_sale_sync(): void
    {
        $tenantId = 'tenant-cutover-no-server-post';
        $token = $this->actingDeviceToken($tenantId, '2026-06-01 00:00:00');
        $txId = (string) Str::uuid();

        $this->pushSaleSkeleton($token, $tenantId, $txId);

        $this->assertNull(
            JournalHeader::where('business_id', $tenantId)->where('source_type', 'sale')->where('source_id', $txId)->first(),
            'the server must stand down for a cutover business and wait for the client\'s own journal'
        );
    }

    public function test_a_non_cutover_business_still_gets_posted_server_side_exactly_as_before(): void
    {
        $tenantId = 'tenant-no-cutover-regression';
        $token = $this->actingDeviceToken($tenantId, null);
        $txId = (string) Str::uuid();

        $this->pushSaleSkeleton($token, $tenantId, $txId);

        $journal = JournalHeader::where('business_id', $tenantId)->where('source_type', 'sale')->where('source_id', $txId)->first();
        $this->assertNotNull($journal, 'a business with no client_gl_posting_enabled_at must keep posting server-side, unchanged');
        $this->assertSame('posted', $journal->status);
    }

    public function test_a_client_posted_journal_is_mirrored_and_the_server_never_double_posts_the_same_sale(): void
    {
        $tenantId = 'tenant-cutover-client-journal';
        $token = $this->actingDeviceToken($tenantId, '2026-06-01 00:00:00');
        $txId = (string) Str::uuid();

        $cash = GlAccount::where('business_id', $tenantId)->where('code', '1000')->first();
        $revenue = GlAccount::where('business_id', $tenantId)->where('code', '4000')->first();

        $this->pushClientJournal($token, $tenantId, $txId, $cash, $revenue, 40.0);
        // The sale's own transaction/items/payments sync afterward, exactly
        // as a real till would — this must NOT create a second journal.
        $this->pushSaleSkeleton($token, $tenantId, $txId);

        $journals = JournalHeader::where('business_id', $tenantId)->where('source_type', 'sale')->where('source_id', $txId)->get();
        $this->assertCount(1, $journals, 'exactly one journal must exist for this sale — no server double-post');
        $this->assertSame('posted', $journals->first()->status);
        $this->assertCount(2, JournalLine::where('journal_header_id', $journals->first()->id)->get());
        $this->assertCount(2, GeneralLedgerEntry::where('journal_header_id', $journals->first()->id)->get());
        $this->assertSame(40.0, $cash->fresh()->balance());
        $this->assertSame(40.0, $revenue->fresh()->balance());
    }

    public function test_a_journal_line_referencing_another_businesss_gl_account_is_rejected(): void
    {
        $tenantId = 'tenant-cutover-cross-tenant-a';
        $token = $this->actingDeviceToken($tenantId, '2026-06-01 00:00:00');
        $otherTenantId = 'tenant-cutover-cross-tenant-b';
        $this->actingDeviceToken($otherTenantId, '2026-06-01 00:00:00');

        $foreignAccount = GlAccount::where('business_id', $otherTenantId)->where('code', '4000')->first();
        $headerId = (string) Str::uuid();

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', ['records' => [[
            'table' => 'journal_headers', 'uuid' => $headerId, 'operation' => 'upsert', 'payload' => [
                'business_id' => $tenantId, 'journal_number' => 'JNL-2026-TEST1', 'trans_date' => '2026-06-10',
                'source_type' => 'sale', 'source_id' => (string) Str::uuid(), 'status' => 'posted',
            ],
            'updated_at' => now()->toIso8601String(),
        ]]])->assertOk();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', ['records' => [[
            'table' => 'journal_lines', 'uuid' => (string) Str::uuid(), 'operation' => 'upsert', 'payload' => [
                'journal_header_id' => $headerId, 'gl_account_id' => $foreignAccount->id, 'debit' => 40, 'credit' => 0,
            ],
            'updated_at' => now()->toIso8601String(),
        ]]])->assertOk();

        $this->assertNotEmpty($response->json('errors'), 'pushing a line against another business\'s gl_account must be rejected');
        $this->assertCount(0, JournalLine::where('journal_header_id', $headerId)->get());
    }

    public function test_set_client_gl_posting_enabled_command_publishes_a_pullable_sync_record(): void
    {
        $tenantId = 'tenant-cutover-command';
        $this->actingDeviceToken($tenantId);

        $this->artisan('accounting:set-client-gl-posting-enabled', [
            'business' => $tenantId,
            'datetime' => '2026-07-01 00:00:00',
        ])->assertSuccessful();

        $this->assertSame('2026-07-01 00:00:00', Business::find($tenantId)->client_gl_posting_enabled_at->toDateTimeString());

        $record = SyncRecord::where('business_id', $tenantId)->where('table_name', 'accounting_settings')->first();
        $this->assertNotNull($record, 'the cutover flag must be published so a device can ever learn about it');
        $this->assertSame('2026-07-01T00:00:00+00:00', $record->payload['client_gl_posting_enabled_at']);
    }

    public function test_chart_of_accounts_seeding_publishes_pullable_sync_records(): void
    {
        $tenantId = 'tenant-cutover-coa-sync';
        $this->actingDeviceToken($tenantId);

        $this->assertGreaterThan(0, SyncRecord::where('business_id', $tenantId)->where('table_name', 'account_categories')->count());
        $this->assertGreaterThan(0, SyncRecord::where('business_id', $tenantId)->where('table_name', 'account_sub_categories')->count());
        $this->assertGreaterThan(0, SyncRecord::where('business_id', $tenantId)->where('table_name', 'gl_accounts')->count());

        $accountCount = AccountCategory::where('business_id', $tenantId)->count();
        $this->assertSame($accountCount, SyncRecord::where('business_id', $tenantId)->where('table_name', 'account_categories')->count());
    }

    public function test_post_pending_sales_defers_within_the_grace_period_and_posts_after_it_elapses(): void
    {
        $tenantId = 'tenant-cutover-sweep';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create([
            'id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD',
            'accounting_go_live_date' => '2026-01-01', 'client_gl_posting_enabled_at' => '2026-06-01 00:00:00',
        ]);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);

        // A straggler sale that arrived via sync (items+payments included)
        // but never got its own client-posted journal — as if it came from
        // an old app version that doesn't know how to post locally.
        $txId = (string) Str::uuid();
        Transaction::create([
            'id' => $txId, 'business_id' => $tenantId, 'user_id' => (string) Str::uuid(), 'subtotal' => 40,
            'tax_total' => 0, 'discount_total' => 0, 'total' => 40, 'base_currency' => 'USD', 'status' => 'completed',
        ]);
        Transaction::where('id', $txId)->update(['created_at' => now()->subMinutes(30)]);
        TransactionItem::create([
            'id' => (string) Str::uuid(), 'transaction_id' => $txId, 'product_id' => (string) Str::uuid(),
            'product_name' => 'Widget', 'quantity' => 1, 'unit_price' => 40, 'line_total' => 40,
        ]);
        Payment::create([
            'id' => (string) Str::uuid(), 'transaction_id' => $txId, 'method' => 'Cash',
            'amount' => 40, 'currency_code' => 'USD', 'base_equivalent' => 40,
        ]);

        $this->artisan(PostPendingSales::class)->assertSuccessful();
        $this->assertNull(
            JournalHeader::where('business_id', $tenantId)->where('source_id', $txId)->first(),
            'inside the grace period the sweep must still defer to the client'
        );

        Transaction::where('id', $txId)->update(['created_at' => now()->subHours(2)]);

        $this->artisan(PostPendingSales::class)->assertSuccessful();
        $journal = JournalHeader::where('business_id', $tenantId)->where('source_id', $txId)->first();
        $this->assertNotNull($journal, 'past the grace period the sweep must post the straggler server-side');
        $this->assertSame('posted', $journal->status);
    }
}
