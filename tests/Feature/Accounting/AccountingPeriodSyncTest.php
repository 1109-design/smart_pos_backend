<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\ApprovalRequest;
use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Periods are closed / reopened on a device and synced; the server checks
 * who may do it and enforces closed periods per module on device journals.
 */
class AccountingPeriodSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BIZ = 'tenant-periods';

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => self::BIZ, 'business_name' => self::BIZ, 'owner_email' => self::BIZ.'@example.com']);
        Business::create(['id' => self::BIZ, 'name' => self::BIZ, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness(self::BIZ);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->device = Device::create(['tenant_id' => self::BIZ, 'name' => 'Till', 'device_identifier' => (string) Str::uuid(), 'is_revoked' => false]);
    }

    private function tokenFor(string $role): string
    {
        $user = User::factory()->create(['business_id' => self::BIZ]);
        $user->assignRole($role);
        $token = $user->createToken('t')->plainTextToken;
        $this->device->update(['token_id' => (int) explode('|', $token)[0]]);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function push(string $token, string $table, string $uuid, array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', ['records' => [[
            'table' => $table, 'uuid' => $uuid, 'operation' => 'upsert',
            'payload' => $payload, 'updated_at' => now()->toIso8601String(),
        ]]])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function june(string $module = 'finance', string $status = 'closed', array $extra = []): array
    {
        return [
            'business_id' => self::BIZ, 'module' => $module,
            'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
            'status' => $status, 'closed_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(), ...$extra,
        ];
    }

    private function journal(string $sourceType, string $date = '2026-06-15', array $header = []): array
    {
        $id = (string) Str::uuid();
        $cash = GlAccount::where('business_id', self::BIZ)->where('code', '1000')->value('id');
        $sales = GlAccount::where('business_id', self::BIZ)->where('code', '4000')->value('id');

        return [$id, [
            'header' => [
                'journal_number' => 'JNL-001-202606-'.substr($id, 0, 4), 'trans_date' => $date,
                'source_type' => $sourceType, 'source_id' => $id, 'posting_key' => "{$sourceType}:{$id}:post",
                'posted_device_id' => $this->device->device_identifier, 'status' => 'posted', ...$header,
            ],
            'lines' => [
                ['id' => (string) Str::uuid(), 'gl_account_id' => $cash, 'debit' => 10, 'credit' => 0],
                ['id' => (string) Str::uuid(), 'gl_account_id' => $sales, 'debit' => 0, 'credit' => 10],
            ],
        ]];
    }

    public function test_an_owner_closes_a_period(): void
    {
        $id = (string) Str::uuid();
        $this->push($this->tokenFor('business_owner'), 'accounting_periods', $id, $this->june());

        $this->assertSame('closed', AccountingPeriod::findOrFail($id)->status);
    }

    public function test_a_cashier_cannot_close_a_period_without_approval(): void
    {
        $id = (string) Str::uuid();
        $response = $this->push($this->tokenFor('cashier'), 'accounting_periods', $id, $this->june());

        $this->assertStringContainsString('finance.period.close', (string) $response->json('errors.0.reason'));
        $this->assertNull(AccountingPeriod::find($id));
    }

    public function test_a_till_signed_in_as_a_cashier_can_push_an_approved_close(): void
    {
        $approval = ApprovalRequest::create([
            'id' => (string) Str::uuid(), 'business_id' => self::BIZ, 'subject_type' => 'AccountingPeriod',
            'subject_id' => 'x', 'action' => 'period_close', 'requested_by_user_id' => (string) Str::uuid(),
            'status' => 'approved',
        ]);
        $id = (string) Str::uuid();

        $this->push($this->tokenFor('cashier'), 'accounting_periods', $id, $this->june(extra: ['approval_request_id' => $approval->id]));

        $this->assertSame('closed', AccountingPeriod::findOrFail($id)->status);
    }

    public function test_a_locked_period_cannot_be_reopened(): void
    {
        $id = (string) Str::uuid();
        $owner = $this->tokenFor('business_owner');
        $this->push($owner, 'accounting_periods', $id, $this->june(status: 'locked'));

        $response = $this->push($owner, 'accounting_periods', $id, $this->june(status: 'open'));

        $this->assertStringContainsString('locked', (string) $response->json('errors.0.reason'));
        $this->assertSame('locked', AccountingPeriod::findOrFail($id)->status);
    }

    public function test_a_closed_sales_period_blocks_sales_but_not_stock(): void
    {
        $owner = $this->tokenFor('business_owner');
        $this->push($owner, 'accounting_periods', (string) Str::uuid(), $this->june('sales'));

        [$saleId, $sale] = $this->journal('sale');
        $this->assertSame('closed_period', $this->push($owner, 'journal_postings', $saleId, $sale)->json('errors.0.code'));

        [$grvId, $grv] = $this->journal('grv');
        $this->assertEmpty($this->push($owner, 'journal_postings', $grvId, $grv)->json('errors'));
        $this->assertNull(JournalHeader::find($saleId));
    }

    public function test_a_closed_finance_period_blocks_every_module(): void
    {
        $owner = $this->tokenFor('business_owner');
        $this->push($owner, 'accounting_periods', (string) Str::uuid(), $this->june('finance'));

        foreach (['sale', 'grv', 'expense', 'supplier_invoice'] as $type) {
            [$id, $bundle] = $this->journal($type);
            $this->assertSame('closed_period', $this->push($owner, 'journal_postings', $id, $bundle)->json('errors.0.code'), $type);
        }
    }

    public function test_a_journal_outside_the_closed_period_posts(): void
    {
        $owner = $this->tokenFor('business_owner');
        $this->push($owner, 'accounting_periods', (string) Str::uuid(), $this->june('finance'));

        [$id, $bundle] = $this->journal('sale', '2026-07-01');

        $this->assertEmpty($this->push($owner, 'journal_postings', $id, $bundle)->json('errors'));
    }

    public function test_module_rules(): void
    {
        $this->assertSame('sales', AccountingPeriod::moduleFor('sale'));
        $this->assertSame('stock', AccountingPeriod::moduleFor('grv'));
        $this->assertSame('ar', AccountingPeriod::moduleFor('customer_receipt'));
        $this->assertSame('ap', AccountingPeriod::moduleFor('supplier_payment'));
        $this->assertSame('finance', AccountingPeriod::moduleFor('expense'));
        $this->assertSame('finance', AccountingPeriod::moduleFor(null));
    }
}
