<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\IntegrityCheckRun;
use App\Models\Accounting\JournalTemplate;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Device;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\IntegrityCheckService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 12: the audit trail from devices is append-only; the ledger
 * summary devices compare against; the nightly integrity check.
 */
class IntegrityAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private const BIZ = 'tenant-books';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => self::BIZ, 'business_name' => self::BIZ, 'owner_email' => self::BIZ.'@example.com']);
        Business::create(['id' => self::BIZ, 'name' => self::BIZ, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness(self::BIZ);
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['business_id' => self::BIZ]);
        $user->assignRole('business_owner');
        $this->token = $user->createToken('t')->plainTextToken;
        Device::create([
            'tenant_id' => self::BIZ, 'name' => 'Till', 'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $this->token)[0], 'is_revoked' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function push(string $table, string $uuid, array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/v1/sync/push', ['records' => [[
            'table' => $table, 'uuid' => $uuid, 'operation' => 'upsert',
            'payload' => $payload, 'updated_at' => now()->toIso8601String(),
        ]]])->assertOk()->assertJsonPath('errors', []);
    }

    private function ledgerLine(string $code, float $debit, float $credit, string $date = '2026-09-10', ?string $journalId = null): void
    {
        $account = DB::table('gl_accounts')->where('business_id', self::BIZ)->where('code', $code)->value('id');
        GeneralLedgerEntry::unguarded(fn () => DB::table('general_ledger')->insert([
            'id' => (string) Str::uuid(), 'business_id' => self::BIZ, 'trans_date' => $date,
            'journal_header_id' => $journalId ?? (string) Str::uuid(), 'gl_account_id' => $account,
            'debit' => $debit, 'credit' => $credit, 'currency_code' => 'USD', 'exchange_rate' => 1,
            'foreign_debit' => 0, 'foreign_credit' => 0, 'status' => 'active',
            'created_at' => now(),
        ]));
    }

    public function test_the_audit_trail_is_append_only(): void
    {
        $id = (string) Str::uuid();
        $entry = [
            'business_id' => self::BIZ, 'user_id' => 'u-1', 'device_id' => 'dev-1', 'model' => 'products',
            'record_id' => 'p-1', 'action' => 'update', 'before' => ['price' => 1], 'after' => ['price' => 2],
            'at' => now()->toIso8601String(),
        ];
        $this->push('audit_logs', $id, $entry);
        $this->push('audit_logs', $id, [...$entry, 'after' => ['price' => 99]]);

        $log = AuditLog::findOrFail($id);
        $this->assertSame(['price' => 2], $log->after);
        $this->assertSame('dev-1', $log->device_id);
    }

    public function test_the_ledger_summary_is_by_month(): void
    {
        $this->ledgerLine('1000', 100, 0);
        $this->ledgerLine('4000', 0, 100);
        $this->ledgerLine('1000', 5, 0, '2026-10-01');
        $this->ledgerLine('4000', 0, 5, '2026-10-01');

        $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/v1/accounting/ledger-summary')
            ->assertOk()
            ->assertJsonPath('months.2026-09.lines', 2)
            ->assertJsonPath('months.2026-09.debit', 100)
            ->assertJsonPath('months.2026-10.credit', 5);
    }

    public function test_the_nightly_check_stores_what_it_finds(): void
    {
        $this->ledgerLine('1000', 100, 0);
        $this->ledgerLine('4000', 0, 100);
        $this->artisan('accounting:integrity-check')->assertSuccessful();
        $run = IntegrityCheckRun::where('business_id', self::BIZ)->latest('id')->firstOrFail();
        $this->assertTrue($run->ok);

        $this->ledgerLine('1000', 7, 0); // a one-sided line
        $this->artisan('accounting:integrity-check', ['--business' => self::BIZ])->assertSuccessful();
        $run = IntegrityCheckRun::where('business_id', self::BIZ)->latest('id')->firstOrFail();
        $this->assertFalse($run->ok);
        $failed = collect($run->findings)->where('ok', false)->pluck('check')->all();
        $this->assertContains('trial_balance', $failed);
    }

    public function test_a_template_schedule_syncs_without_losing_the_template(): void
    {
        $id = (string) Str::uuid();
        $this->push('journal_templates', $id, [
            'business_id' => self::BIZ, 'name' => 'Rent', 'journal_type' => 'general',
            'lines' => [['line_type' => 'gl', 'debit' => 500]],
        ]);
        $this->push('journal_templates', $id, [
            'business_id' => self::BIZ, 'recur_frequency' => 'monthly',
            'recur_next_date' => '2026-11-01', 'recur_active' => true,
        ]);

        $template = JournalTemplate::findOrFail($id);
        $this->assertSame('Rent', $template->name);
        $this->assertCount(1, $template->lines);
        $this->assertSame('monthly', $template->recur_frequency);
        $this->assertTrue($template->recur_active);
        $this->assertSame('2026-11-01', $template->recur_next_date->toDateString());
    }

    public function test_the_nightly_check_compares_stock_and_cash_like_the_device(): void
    {
        $service = app(IntegrityCheckService::class);
        $find = fn (string $check) => collect($service->findings(self::BIZ))->firstWhere('check', $check);

        // 10 on hand at 5 = 50, and 50 in Inventory.
        DB::table('products')->insert([
            'id' => (string) Str::uuid(), 'business_id' => self::BIZ, 'name' => 'Widget', 'price' => 9,
            'cost_price' => 5, 'stock_quantity' => 10, 'track_stock' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ledgerLine('1200', 50, 0);
        $this->ledgerLine('3020', 0, 50);
        $this->assertTrue($find('inventory')['ok']);
        $this->ledgerLine('1200', 7, 0);
        $this->ledgerLine('3020', 0, 7);
        $inventory = $find('inventory');
        $this->assertFalse($inventory['ok']);
        $this->assertEquals(-7, $inventory['difference']);

        // A cash sale of 20: the sale journal's cash line matches its payment.
        $tx = (string) Str::uuid();
        DB::table('transactions')->insert([
            'id' => $tx, 'business_id' => self::BIZ, 'user_id' => (string) Str::uuid(),
            'subtotal' => 20, 'total' => 20, 'base_currency' => 'USD', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payments')->insert([
            'id' => (string) Str::uuid(), 'transaction_id' => $tx, 'method' => 'cash', 'amount' => 20,
            'currency_code' => 'USD', 'base_equivalent' => 20,
        ]);
        $journal = (string) Str::uuid();
        DB::table('journal_headers')->insert([
            'id' => $journal, 'business_id' => self::BIZ, 'journal_number' => 'JNL-1', 'trans_date' => '2026-09-10',
            'source_type' => 'sale', 'source_id' => $tx, 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ledgerLine('1000', 20, 0, '2026-09-10', $journal);
        $this->ledgerLine('4000', 0, 20, '2026-09-10', $journal);
        $this->assertTrue($find('cash')['ok']);
    }

    public function test_server_side_edits_are_audited_but_device_pushes_are_not_twice(): void
    {
        $owner = User::where('business_id', self::BIZ)->firstOrFail();
        $this->actingAs($owner);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => self::BIZ, 'name' => 'Acme']);
        $supplier->update(['name' => 'Acme Ltd']);

        $trail = AuditLog::where('model', 'suppliers')->where('record_id', $supplier->id)->orderBy('created_at')->get();
        $this->assertSame(['create', 'update'], $trail->pluck('action')->all());
        $this->assertSame('server', $trail[1]->device_id);
        $this->assertSame((string) $owner->id, $trail[1]->user_id);
        $this->assertSame('Acme', $trail[1]->before['name']);
        $this->assertSame('Acme Ltd', $trail[1]->after['name']);

        $this->app['auth']->forgetGuards();
        $pushed = (string) Str::uuid();
        $this->push('suppliers', $pushed, ['business_id' => self::BIZ, 'name' => 'From a till']);
        $this->assertSame(0, AuditLog::where('record_id', $pushed)->where('device_id', 'server')->count());
    }
}
