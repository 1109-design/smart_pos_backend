<?php

namespace Tests\Feature;

use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\JournalHeader;
use App\Models\Accounting\JournalLine;
use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 2026-09-28 sync audit finding C1: SyncProcessor's 'journal_headers' case
 * only ever logged a warning when a second journal arrived for a source
 * that already had one — never rejected it — so the real-world race between
 * a device staying offline past the accounting sweep's grace period
 * (PostPending*.php posts its own server-side journal for the source) and
 * that same device's own already-posted local journal finally syncing up
 * (a different uuid) silently doubled revenue/COGS/cash for the event, with
 * nothing catching it since both duplicate entries still balance on their
 * own. Fixed by wiring the sync-push path into the same idempotency_key
 * mechanism JournalService::createDraft() already uses server-side, scoped
 * to exactly the source types that post at most one journal ever per
 * source. Also closes a sibling gap: journal_lines/general_ledger could
 * previously be written for a journal_header_id that doesn't exist at all
 * (no DB-level foreign key on that column), which the duplicate-rejection
 * fix alone would otherwise have left as an orphan-record loophole, since
 * journal_headers/journal_lines/general_ledger are each their own
 * independent sync push group.
 */
class SyncJournalDuplicatePostGuardTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithChartOfAccounts(string $tenantId): void
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingOwnerToken(string $tenantId): string
    {
        $owner = User::factory()->create(['email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $plain = $owner->createToken('sync-test')->plainTextToken;
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

    private function pushJournalHeader(string $token, string $uuid, string $tenantId, string $sourceType, string $sourceId): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'journal_headers',
                    'uuid' => $uuid,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'journal_number' => 'JV-'.substr($uuid, 0, 8),
                        'trans_date' => now()->toDateString(),
                        'source_type' => $sourceType,
                        'source_id' => $sourceId,
                        'status' => 'posted',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_a_late_client_push_is_rejected_when_the_sweep_already_posted_the_same_source(): void
    {
        $tenantId = 'tenant-jnl-dupe-sweep-race';
        $this->tenantWithChartOfAccounts($tenantId);
        $token = $this->actingOwnerToken($tenantId);
        $paymentId = (string) Str::uuid();

        // Simulate the server's own >1hr catch-up sweep having already
        // posted, via the real service (not a hand-rolled fixture) so this
        // exercises the exact idempotency_key value production code writes.
        app(JournalService::class)->createDraft(
            $tenantId, now()->toDateString(), 'salary_payment', $paymentId,
            'Salary payment — sweep', idempotencyKey: "salary_payment:{$paymentId}",
        );
        $this->assertSame(1, JournalHeader::where('business_id', $tenantId)->where('source_id', $paymentId)->count());

        // The originating device's own already-locally-posted journal (a
        // different uuid) now finally reaches the server.
        $response = $this->pushJournalHeader($token, (string) Str::uuid(), $tenantId, 'salary_payment', $paymentId);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertCount(1, $response->json('errors'));
        $this->assertStringContainsString('refusing to post a duplicate', $response->json('errors.0.reason'));

        // Exactly one journal for this source, never two.
        $this->assertSame(1, JournalHeader::where('business_id', $tenantId)->where('source_id', $paymentId)->count());
    }

    public function test_a_retry_of_the_same_journal_uuid_is_not_treated_as_a_duplicate(): void
    {
        $tenantId = 'tenant-jnl-dupe-retry';
        $this->tenantWithChartOfAccounts($tenantId);
        $token = $this->actingOwnerToken($tenantId);
        $uuid = (string) Str::uuid();
        $sourceId = (string) Str::uuid();

        $first = $this->pushJournalHeader($token, $uuid, $tenantId, 'salary_payment', $sourceId);
        $first->assertOk();
        $this->assertCount(1, $first->json('accepted'));

        // A genuine retry (same client uuid, e.g. a dropped-response resend)
        // must still succeed as a plain idempotent upsert.
        $second = $this->pushJournalHeader($token, $uuid, $tenantId, 'salary_payment', $sourceId);
        $second->assertOk();
        $this->assertCount(1, $second->json('accepted'));
        $this->assertSame(1, JournalHeader::where('id', $uuid)->count());
    }

    /**
     * Regression guard for the fix's own narrow scoping: depreciation
     * legitimately posts one journal per month against the same asset —
     * applying the duplicate guard here would have silently blocked real,
     * distinct monthly entries.
     */
    public function test_a_legitimately_multi_journal_source_type_is_not_blocked(): void
    {
        $tenantId = 'tenant-jnl-dupe-depreciation';
        $this->tenantWithChartOfAccounts($tenantId);
        $token = $this->actingOwnerToken($tenantId);
        $assetId = (string) Str::uuid();

        $jan = $this->pushJournalHeader($token, (string) Str::uuid(), $tenantId, 'depreciation', $assetId);
        $jan->assertOk();
        $this->assertCount(1, $jan->json('accepted'));

        $feb = $this->pushJournalHeader($token, (string) Str::uuid(), $tenantId, 'depreciation', $assetId);
        $feb->assertOk();
        $this->assertCount(1, $feb->json('accepted'));

        $this->assertSame(2, JournalHeader::where('business_id', $tenantId)->where('source_type', 'depreciation')->where('source_id', $assetId)->count());
    }

    /**
     * journal_lines is CHILD_SCOPED_MODELS (parented via journal_header_id),
     * so a missing parent is handled upstream by assertOwnership()'s own
     * MissingParentRecordException deferral, not a hard rejection — it
     * never even reaches handleUpsert(). Proves that mechanism still covers
     * this table (no orphan write), even without a duplicate explicit check.
     */
    public function test_journal_line_is_deferred_not_orphan_written_for_a_journal_header_that_does_not_exist(): void
    {
        $tenantId = 'tenant-jnl-orphan-line';
        $this->tenantWithChartOfAccounts($tenantId);
        $token = $this->actingOwnerToken($tenantId);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'journal_lines',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'journal_header_id' => (string) Str::uuid(),
                        'gl_account_id' => (string) Str::uuid(),
                        'debit' => 100,
                        'credit' => 0,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertCount(1, $response->json('deferred'));
        $this->assertSame(0, JournalLine::count());
    }

    public function test_general_ledger_entry_is_rejected_for_a_journal_header_that_does_not_exist(): void
    {
        $tenantId = 'tenant-jnl-orphan-gl';
        $this->tenantWithChartOfAccounts($tenantId);
        $token = $this->actingOwnerToken($tenantId);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'general_ledger',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'journal_header_id' => (string) Str::uuid(),
                        'gl_account_id' => (string) Str::uuid(),
                        'debit' => 100,
                        'credit' => 0,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertCount(1, $response->json('errors'));
        $this->assertSame(0, GeneralLedgerEntry::count());
    }
}
