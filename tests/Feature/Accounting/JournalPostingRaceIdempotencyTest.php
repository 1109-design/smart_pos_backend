<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\JournalHeader;
use App\Models\Accounting\JournalLine;
use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\OpeningBalanceService;
use App\Services\Accounting\SalePostingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Covers the P1 gap: journal-posting idempotency previously relied only on
 * an application-level check-then-insert (existingJournal()-style lookup,
 * then JournalService::createDraft()) with no database-level backstop, so a
 * genuine race — two near-simultaneous triggers for the same source, e.g. a
 * sync retry racing the original request — could double-post. This suite
 * proves the new journal_headers_source_unique constraint plus
 * JournalService::createDraft()'s catch-and-return-existing behavior close
 * that window, both at the JournalService level directly and through the
 * posting services that sit on top of it.
 */
class JournalPostingRaceIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private JournalService $journals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->journals = app(JournalService::class);
    }

    protected function tearDown(): void
    {
        // A failed Mockery expectation throws from close() — must not skip
        // RefreshDatabase's own tearDown (the wrapping rollback) or it
        // leaves a dangling transaction that breaks every later test in
        // the run.
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    private function makeBusiness(string $id = 'biz-race-1'): string
    {
        Tenant::create(['id' => $id, 'business_name' => $id, 'owner_email' => "{$id}@example.com"]);
        (new ChartOfAccountsSeeder)->seedForBusiness($id);

        return $id;
    }

    // ── Core mechanism: JournalService::createDraft() itself ──────────────

    public function test_calling_create_draft_twice_with_the_same_idempotency_key_returns_the_same_journal(): void
    {
        $businessId = $this->makeBusiness();

        $first = $this->journals->createDraft($businessId, '2026-09-05', 'sale', 'tx-race-1', 'Sale', idempotencyKey: 'sale:tx-race-1');
        $second = $this->journals->createDraft($businessId, '2026-09-05', 'sale', 'tx-race-1', 'Sale (retry)', idempotencyKey: 'sale:tx-race-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalHeader::where('idempotency_key', 'sale:tx-race-1')->count());
    }

    public function test_manual_journals_with_no_idempotency_key_are_never_treated_as_duplicates_of_each_other(): void
    {
        $businessId = $this->makeBusiness();

        $first = $this->journals->createDraft($businessId, '2026-09-05');
        $second = $this->journals->createDraft($businessId, '2026-09-05');

        $this->assertNotSame($first->id, $second->id);
        $this->assertNull($first->idempotency_key);
        $this->assertNull($second->idempotency_key);
    }

    /**
     * The constraint deliberately is NOT on (business_id, source_type,
     * source_id) — AssetPostingService posts one depreciation journal per
     * month against the same asset, GrvPostingService posts one journal per
     * partial receipt against the same GRV, and ExpensePostingService
     * reverses-then-reposts on edit — all legitimately reuse the same
     * source_type/source_id across multiple, simultaneously-existing
     * journal_headers rows. Only opt-in $idempotencyKey is unique.
     */
    public function test_the_same_source_type_and_id_can_still_have_multiple_journals_when_not_opted_into_idempotency(): void
    {
        $businessId = $this->makeBusiness();

        $first = $this->journals->createDraft($businessId, '2026-09-05', 'depreciation', 'asset-1', 'Jan depreciation');
        $second = $this->journals->createDraft($businessId, '2026-10-05', 'depreciation', 'asset-1', 'Feb depreciation');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(
            2,
            JournalHeader::where('business_id', $businessId)
                ->where('source_type', 'depreciation')
                ->where('source_id', 'asset-1')
                ->count()
        );
    }

    // ── Database-level proof, bypassing the service entirely ──────────────

    public function test_the_database_rejects_a_duplicate_idempotency_key_even_without_going_through_journal_service(): void
    {
        $businessId = $this->makeBusiness();

        JournalHeader::insert([[
            'id' => (string) Str::uuid(),
            'business_id' => $businessId,
            'journal_number' => 'JNL-RAW-1',
            'trans_date' => '2026-09-05',
            'source_type' => 'sale',
            'source_id' => 'tx-raw-1',
            'idempotency_key' => 'sale:tx-raw-1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $this->expectException(QueryException::class);

        JournalHeader::insert([[
            'id' => (string) Str::uuid(),
            'business_id' => $businessId,
            'journal_number' => 'JNL-RAW-2',
            'trans_date' => '2026-09-05',
            'source_type' => 'sale',
            'source_id' => 'tx-raw-1',
            'idempotency_key' => 'sale:tx-raw-1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
    }

    // ── SalePostingService: the guard added around createDraft()'s result ─

    private function readyTransaction(string $businessId): Transaction
    {
        Tenant::create(['id' => $businessId, 'business_name' => $businessId, 'owner_email' => $businessId.'@example.com']);
        Business::create([
            'id' => $businessId,
            'name' => $businessId,
            'currency_code' => 'USD',
            'accounting_go_live_date' => '2026-01-01',
        ]);
        (new ChartOfAccountsSeeder)->seedForBusiness($businessId);

        $tenantId = $businessId;
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

        $txId = (string) Str::uuid();
        $push = fn (array $record) => $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/sync/push', ['records' => [$record]])
            ->assertOk();

        $push([
            'table' => 'transactions',
            'uuid' => $txId,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $businessId,
                'user_id' => (string) Str::uuid(),
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

        // The push above already auto-posted a journal via the real
        // SalePostingService (SyncProcessor's hooks). Roll that back to put
        // the transaction back in "not yet posted" state, so the guard
        // under test — not the ordinary existingJournal() pre-check — is
        // what's actually exercised below.
        $posted = JournalHeader::where('business_id', $businessId)->where('source_type', 'sale')->where('source_id', $txId)->first();
        if ($posted) {
            GeneralLedgerEntry::where('journal_header_id', $posted->id)->delete();
            JournalLine::where('journal_header_id', $posted->id)->delete();
            $posted->delete();
        }

        return Transaction::where('id', $txId)->firstOrFail();
    }

    public function test_sale_posting_service_does_not_crash_or_duplicate_when_create_draft_hands_back_a_posted_header(): void
    {
        $businessId = 'biz-race-sale';
        $transaction = $this->readyTransaction($businessId);

        // Simulate the race: another in-flight request already won and
        // posted a journal for this exact sale, so createDraft() returns
        // that (non-draft) header instead of a fresh one.
        $winningHeader = new JournalHeader([
            'id' => (string) Str::uuid(),
            'business_id' => $businessId,
            'journal_number' => 'JNL-2026-99999',
            'trans_date' => '2026-06-10',
            'source_type' => 'sale',
            'source_id' => $transaction->id,
            'status' => 'posted',
        ]);
        $winningHeader->exists = true;

        $mockJournals = Mockery::mock(JournalService::class);
        $mockJournals->shouldReceive('createDraft')->once()->andReturn($winningHeader);
        $mockJournals->shouldNotReceive('addLine');
        $mockJournals->shouldNotReceive('post');

        $service = new SalePostingService($mockJournals);

        // Must not throw — the old behavior (no guard) would let addLine()
        // throw "Cannot add a line ... it is posted, not draft" here, which
        // would only have been masked by SalePostingService::post()'s own
        // outer try/catch, logging a misleading "failed to post sale"
        // warning for what is actually a benign lost race.
        $service->postIfReady($transaction);

        $this->assertTrue(true);
    }

    // ── OpeningBalanceService: both methods lacked any try/catch, so a lost
    //    race would previously have thrown an uncaught RuntimeException ────

    public function test_customer_opening_balance_lost_race_is_a_silent_noop(): void
    {
        $businessId = $this->makeBusiness('biz-race-cust');
        Business::create(['id' => $businessId, 'name' => $businessId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);

        $winningHeader = new JournalHeader([
            'id' => (string) Str::uuid(),
            'business_id' => $businessId,
            'journal_number' => 'JNL-2026-99998',
            'trans_date' => '2026-06-10',
            'source_type' => 'opening_balance_customer',
            'source_id' => 'cust-1',
            'status' => 'posted',
        ]);
        $winningHeader->exists = true;

        $mockJournals = Mockery::mock(JournalService::class);
        $mockJournals->shouldReceive('createDraft')->once()->andReturn($winningHeader);
        $mockJournals->shouldNotReceive('addLine');
        $mockJournals->shouldNotReceive('post');

        $service = new OpeningBalanceService($mockJournals, new ChartOfAccountsSeeder);

        // Must return quietly, matching the class's documented "silent
        // no-op ... arriving from an offline device that might retry".
        $service->recordCustomerOpeningBalance($businessId, 'cust-1', 100.0, '2026-06-10');

        $this->assertTrue(true);
    }

    public function test_supplier_opening_balance_lost_race_throws_the_documented_already_recorded_error(): void
    {
        $businessId = $this->makeBusiness('biz-race-supp');
        Business::create(['id' => $businessId, 'name' => $businessId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);

        $winningHeader = new JournalHeader([
            'id' => (string) Str::uuid(),
            'business_id' => $businessId,
            'journal_number' => 'JNL-2026-99997',
            'trans_date' => '2026-06-10',
            'source_type' => 'opening_balance_supplier',
            'source_id' => 'supp-1',
            'status' => 'posted',
        ]);
        $winningHeader->exists = true;

        $mockJournals = Mockery::mock(JournalService::class);
        $mockJournals->shouldReceive('createDraft')->once()->andReturn($winningHeader);
        $mockJournals->shouldNotReceive('addLine');
        $mockJournals->shouldNotReceive('post');

        $service = new OpeningBalanceService($mockJournals, new ChartOfAccountsSeeder);

        $this->expectExceptionMessage('An opening balance has already been recorded for this supplier.');
        $service->recordSupplierOpeningBalance($businessId, 'supp-1', 100.0, '2026-06-10');
    }
}
