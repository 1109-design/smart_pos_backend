<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Device;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Devices push each journal as one 'journal_postings' bundle; the server
 * stores it whole or rejects it whole with a reason code, and relays it to
 * the other devices as ordinary journal records. See JournalBundleIngestor.
 */
class JournalBundleSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BIZ = 'tenant-bundle';

    private string $token;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => self::BIZ, 'business_name' => self::BIZ, 'owner_email' => self::BIZ.'@example.com']);
        Business::create(['id' => self::BIZ, 'name' => self::BIZ, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness(self::BIZ);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['business_id' => self::BIZ]);
        $user->assignRole('business_owner');
        $this->token = $user->createToken('sync-test')->plainTextToken;
        $this->device = Device::create([
            'tenant_id' => self::BIZ,
            'name' => 'Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $this->token)[0],
            'is_revoked' => false,
        ]);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', self::BIZ)->where('code', $code)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>|null  $lines
     * @return array{id: string, header: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    private function bundle(array $header = [], ?array $lines = null, float $amount = 40): array
    {
        $id = (string) Str::uuid();

        return [
            'id' => $id,
            'header' => [
                'journal_number' => 'JNL-001-202606-'.substr($id, 0, 4),
                'trans_date' => '2026-06-10',
                'source_type' => 'sale',
                'source_id' => 'tx-'.substr($id, 0, 6),
                'posting_key' => 'sale:'.$id.':post',
                'posted_device_id' => $this->device->device_identifier,
                'status' => 'posted',
                ...$header,
            ],
            'lines' => $lines ?? [
                ['id' => (string) Str::uuid(), 'line_no' => 1, 'gl_account_id' => $this->account('1000')->id, 'debit' => $amount, 'credit' => 0],
                ['id' => (string) Str::uuid(), 'line_no' => 2, 'gl_account_id' => $this->account('4000')->id, 'debit' => 0, 'credit' => $amount],
            ],
        ];
    }

    /**
     * @param  array{id: string, header: array<string, mixed>, lines: list<array<string, mixed>>}  $bundle
     */
    private function push(array $bundle): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/v1/sync/push', ['records' => [[
            'table' => 'journal_postings',
            'uuid' => $bundle['id'],
            'operation' => 'insert',
            'payload' => ['header' => $bundle['header'], 'lines' => $bundle['lines']],
            'updated_at' => now()->toIso8601String(),
        ]]])->assertOk();
    }

    private function assertRejected(TestResponse $response, string $code): void
    {
        $this->assertSame($code, $response->json('errors.0.code'), (string) $response->json('errors.0.reason'));
        $this->assertSame(0, JournalHeader::where('business_id', self::BIZ)->count());
        $this->assertSame(0, GeneralLedgerEntry::where('business_id', self::BIZ)->count());
    }

    public function test_a_balanced_journal_is_stored_whole_with_its_ledger_rows(): void
    {
        $bundle = $this->bundle();

        $response = $this->push($bundle);

        $this->assertSame([['table' => 'journal_postings', 'uuid' => $bundle['id']]], array_map(
            fn (array $a): array => ['table' => $a['table'], 'uuid' => $a['uuid']],
            $response->json('accepted'),
        ));
        $header = JournalHeader::findOrFail($bundle['id']);
        $this->assertSame('posted', $header->status);
        $this->assertSame($bundle['header']['posting_key'], $header->posting_key);
        $this->assertCount(2, $header->lines);
        $ledger = GeneralLedgerEntry::where('journal_header_id', $bundle['id'])->get();
        $this->assertEqualsCanonicalizing(array_column($bundle['lines'], 'id'), $ledger->pluck('id')->all());
        $this->assertEqualsWithDelta($ledger->sum('debit'), $ledger->sum('credit'), 0.0001);
    }

    public function test_it_is_relayed_to_other_devices_as_ordinary_journal_records(): void
    {
        $bundle = $this->bundle();
        $this->push($bundle);

        $relayed = SyncRecord::where('business_id', self::BIZ)
            ->whereIn('table_name', ['journal_headers', 'journal_lines', 'general_ledger', 'journal_postings'])
            ->get();
        $this->assertSame(['general_ledger' => 2, 'journal_headers' => 1, 'journal_lines' => 2], $relayed->countBy('table_name')->sortKeys()->all());
        // Not echoed back to the device that posted it.
        $this->assertTrue($relayed->every(fn (SyncRecord $r): bool => $r->device_id === $this->device->id));
    }

    public function test_pushing_the_same_journal_again_is_accepted_without_a_second_copy(): void
    {
        $bundle = $this->bundle();
        $this->push($bundle);

        $response = $this->push($bundle);

        $this->assertEmpty($response->json('errors'));
        $this->assertSame(1, JournalHeader::where('business_id', self::BIZ)->count());
        $this->assertSame(2, GeneralLedgerEntry::where('business_id', self::BIZ)->count());
    }

    public function test_the_same_id_with_different_contents_is_refused(): void
    {
        $bundle = $this->bundle();
        $this->push($bundle);
        $bundle['lines'][0]['debit'] = 99;
        $bundle['lines'][1]['credit'] = 99;

        $response = $this->push($bundle);

        $this->assertSame('conflict', $response->json('errors.0.code'));
        $this->assertEqualsWithDelta(40, JournalHeader::findOrFail($bundle['id'])->lines()->sum('debit'), 0.001);
    }

    public function test_an_unbalanced_journal_is_rejected(): void
    {
        $bundle = $this->bundle();
        $bundle['lines'][1]['credit'] = 30;

        $this->assertRejected($this->push($bundle), 'unbalanced');
    }

    public function test_a_journal_without_lines_is_rejected(): void
    {
        $this->assertRejected($this->push($this->bundle(lines: [])), 'no_lines');
    }

    public function test_a_line_with_both_a_debit_and_a_credit_is_rejected(): void
    {
        $bundle = $this->bundle();
        $bundle['lines'][0]['credit'] = 40;
        $bundle['lines'][1]['debit'] = 40;

        $this->assertRejected($this->push($bundle), 'invalid_line');
    }

    public function test_an_account_from_another_business_is_rejected(): void
    {
        Business::create(['id' => 'other-biz', 'name' => 'Other', 'currency_code' => 'USD']);
        (new ChartOfAccountsSeeder)->seedForBusiness('other-biz');
        $bundle = $this->bundle();
        $bundle['lines'][0]['gl_account_id'] = GlAccount::where('business_id', 'other-biz')->where('code', '1000')->value('id');

        $this->assertRejected($this->push($bundle), 'unknown_account');
    }

    public function test_an_inactive_account_is_rejected(): void
    {
        $this->account('4000')->update(['status' => 'inactive']);

        $this->assertRejected($this->push($this->bundle()), 'inactive_account');
    }

    public function test_a_receivable_line_without_a_customer_is_rejected(): void
    {
        $bundle = $this->bundle();
        $bundle['lines'][0]['gl_account_id'] = $this->account('1100')->id;

        $this->assertRejected($this->push($bundle), 'missing_party');
    }

    public function test_a_receivable_line_with_a_customer_is_accepted(): void
    {
        $bundle = $this->bundle();
        $bundle['lines'][0]['gl_account_id'] = $this->account('1100')->id;
        $bundle['lines'][0]['party_type'] = 'customer';
        $bundle['lines'][0]['party_id'] = 'cust-1';

        $this->assertEmpty($this->push($bundle)->json('errors'));
    }

    public function test_a_journal_in_a_closed_period_is_rejected(): void
    {
        AccountingPeriod::create([
            'business_id' => self::BIZ, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'closed',
        ]);

        $this->assertRejected($this->push($this->bundle()), 'closed_period');
    }

    public function test_a_second_journal_for_the_same_posting_key_is_rejected(): void
    {
        $first = $this->bundle(['posting_key' => 'sale:tx-1:post']);
        $this->push($first);

        $response = $this->push($this->bundle(['posting_key' => 'sale:tx-1:post']));

        $this->assertSame('duplicate', $response->json('errors.0.code'));
        $this->assertSame(1, JournalHeader::where('business_id', self::BIZ)->count());
    }

    public function test_a_reused_journal_number_is_rejected(): void
    {
        $this->push($this->bundle(['journal_number' => 'JNL-001-202606-0001']));

        $response = $this->push($this->bundle(['journal_number' => 'JNL-001-202606-0001']));

        $this->assertSame('duplicate_number', $response->json('errors.0.code'));
    }

    public function test_a_journal_relayed_by_another_device_of_the_business_is_accepted(): void
    {
        $poster = Device::create(['tenant_id' => self::BIZ, 'name' => 'Till 2', 'device_identifier' => (string) Str::uuid(), 'is_revoked' => false]);

        $this->assertEmpty($this->push($this->bundle(['posted_device_id' => $poster->device_identifier]))->json('errors'));
    }

    public function test_a_journal_claiming_another_device_is_rejected(): void
    {
        $this->assertRejected($this->push($this->bundle(['posted_device_id' => 'someone-else'])), 'device_mismatch');
    }

    public function test_a_cashier_cannot_post_a_supplier_payment_journal(): void
    {
        $cashier = User::factory()->create(['business_id' => self::BIZ]);
        $cashier->assignRole('cashier');
        $this->token = $cashier->createToken('sync-test')->plainTextToken;
        $this->device->update(['token_id' => (int) explode('|', $this->token)[0]]);

        $this->assertRejected($this->push($this->bundle(['source_type' => 'supplier_payment'])), 'forbidden');
    }

    public function test_a_reversal_marks_the_original_reversed(): void
    {
        $original = $this->bundle();
        $this->push($original);
        $reversal = $this->bundle([
            'source_type' => 'reversal',
            'source_id' => $original['id'],
            'posting_key' => 'reverse:'.$original['id'],
            'reversal_of_journal_id' => $original['id'],
        ]);

        $this->assertEmpty($this->push($reversal)->json('errors'));

        $this->assertSame('reversed', JournalHeader::findOrFail($original['id'])->status);
        $this->assertSame($reversal['id'], JournalHeader::findOrFail($original['id'])->reversed_by_journal_id);
        $this->assertTrue(GeneralLedgerEntry::where('journal_header_id', $original['id'])->get()->every(fn ($e) => $e->status === 'reversed'));
    }

    public function test_a_reversal_that_arrives_before_its_original_waits_for_it(): void
    {
        $original = $this->bundle();
        $reversal = $this->bundle([
            'source_type' => 'reversal',
            'posting_key' => 'reverse:'.$original['id'],
            'reversal_of_journal_id' => $original['id'],
        ]);

        $this->push($reversal);
        $this->assertNull(JournalHeader::find($reversal['id']));

        $this->push($original);

        $this->assertSame('posted', JournalHeader::findOrFail($reversal['id'])->status);
        $this->assertSame('reversed', JournalHeader::findOrFail($original['id'])->status);
    }

    public function test_an_already_reversed_journal_cannot_be_reversed_again(): void
    {
        $original = $this->bundle();
        $this->push($original);
        $this->push($this->bundle(['posting_key' => 'reverse:'.$original['id'], 'reversal_of_journal_id' => $original['id']]));

        $response = $this->push($this->bundle(['posting_key' => 'reverse:'.$original['id'].':2', 'reversal_of_journal_id' => $original['id']]));

        $this->assertSame('not_reversible', $response->json('errors.0.code'));
    }
}
