<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\IntegrityCheckRun;
use App\Models\Accounting\JournalTemplate;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
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

    private function ledgerLine(string $code, float $debit, float $credit, string $date = '2026-09-10'): void
    {
        $account = DB::table('gl_accounts')->where('business_id', self::BIZ)->where('code', $code)->value('id');
        GeneralLedgerEntry::unguarded(fn () => DB::table('general_ledger')->insert([
            'id' => (string) Str::uuid(), 'business_id' => self::BIZ, 'trans_date' => $date,
            'journal_header_id' => (string) Str::uuid(), 'gl_account_id' => $account,
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
}
