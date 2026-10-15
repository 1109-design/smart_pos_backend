<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\StockPostingRule;
use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\AccountRoleMappingService;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stock posting rules (Phase 6) are edited on a device by an owner or
 * manager and relayed by the server; transfers post through 1210 Stock in
 * Transit.
 */
class StockPostingRuleSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BIZ = 'tenant-stock-rules';

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

    private function push(string $role, string $uuid, array $payload): TestResponse
    {
        $user = User::factory()->create(['business_id' => self::BIZ]);
        $user->assignRole($role);
        $token = $user->createToken('t')->plainTextToken;
        $this->device->update(['token_id' => (int) explode('|', $token)[0]]);
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', ['records' => [[
            'table' => 'stock_posting_rules', 'uuid' => $uuid, 'operation' => 'upsert',
            'payload' => $payload, 'updated_at' => now()->toIso8601String(),
        ]]])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function rule(array $extra = []): array
    {
        $account = fn (string $code) => GlAccount::where('business_id', self::BIZ)->where('code', $code)->value('id');

        return [
            'business_id' => self::BIZ, 'movement_type' => 'DISPOSAL',
            'debit_account_id' => $account('6050'), 'credit_account_id' => $account('1200'),
            'is_active' => true, ...$extra,
        ];
    }

    public function test_an_owner_saves_a_rule(): void
    {
        $id = (string) Str::uuid();
        $this->assertEmpty($this->push('business_owner', $id, $this->rule())->json('errors'));

        $this->assertSame('DISPOSAL', StockPostingRule::findOrFail($id)->movement_type);
    }

    public function test_a_cashier_cannot(): void
    {
        $id = (string) Str::uuid();
        $response = $this->push('cashier', $id, $this->rule());

        $this->assertStringContainsString('owner or manager', (string) $response->json('errors.0.reason'));
        $this->assertNull(StockPostingRule::find($id));
    }

    public function test_unknown_types_and_foreign_accounts_are_refused(): void
    {
        $bad = $this->push('business_owner', (string) Str::uuid(), $this->rule(['movement_type' => 'NOPE']));
        $this->assertStringContainsString('unknown movement type', (string) $bad->json('errors.0.reason'));

        $foreign = $this->push('business_owner', (string) Str::uuid(), $this->rule(['debit_account_id' => (string) Str::uuid()]));
        $this->assertStringContainsString('not an account of this business', (string) $foreign->json('errors.0.reason'));
    }

    public function test_stock_in_transit_and_the_new_stock_sources(): void
    {
        $this->assertSame('1210', app(AccountRoleMappingService::class)->resolve(self::BIZ, 'stock_in_transit')->code);
        $this->assertSame('stock', AccountingPeriod::moduleFor('stock_transfer'));
        $this->assertSame('stock', AccountingPeriod::moduleFor('grv_walk_in'));
    }
}
