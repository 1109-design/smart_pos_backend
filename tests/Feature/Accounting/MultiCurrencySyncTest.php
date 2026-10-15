<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Business;
use App\Models\Device;
use App\Models\Invoice;
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
 * Multi-currency (Phase 9): a foreign invoice paid in its own currency is
 * settled at the invoice's rate, and revaluation has accounts of its own.
 */
class MultiCurrencySyncTest extends TestCase
{
    use RefreshDatabase;

    private const BIZ = 'tenant-fx';

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

    public function test_a_foreign_invoice_paid_in_its_currency_is_settled_at_its_own_rate(): void
    {
        $customerId = (string) Str::uuid();
        $this->push('customers', $customerId, ['business_id' => self::BIZ, 'name' => 'Bob']);
        $invoiceId = (string) Str::uuid();
        // 100 USD owed as 2500 ZWG (25 ZWG per USD).
        $this->push('invoices', $invoiceId, [
            'business_id' => self::BIZ, 'customer_id' => $customerId, 'invoice_number' => 'INV-1',
            'status' => 'sent', 'issue_date' => '2026-10-01', 'subtotal' => 100, 'total' => 100,
            'currency_code' => 'ZWG', 'exchange_rate' => 25, 'created_by_user_id' => (string) Str::uuid(),
        ]);
        // Paid 2500 ZWG when the rate was 30: 83.33 USD came in.
        $this->push('invoice_payments', (string) Str::uuid(), [
            'invoice_id' => $invoiceId, 'business_id' => self::BIZ, 'method' => 'cash', 'amount' => 2500,
            'currency_code' => 'ZWG', 'exchange_rate_used' => 30, 'base_equivalent' => 83.3333,
            'recorded_by_user_id' => (string) Str::uuid(), 'paid_at' => now()->toIso8601String(),
        ]);

        $invoice = Invoice::findOrFail($invoiceId);
        $this->assertSame('ZWG', $invoice->currency_code);
        $this->assertEquals(100, (float) $invoice->amount_paid);
    }

    public function test_revaluation_has_its_own_accounts_and_is_finance(): void
    {
        $roles = app(AccountRoleMappingService::class);
        $this->assertSame('4025', $roles->resolve(self::BIZ, 'fx_unrealised_gain')->code);
        $this->assertSame('6085', $roles->resolve(self::BIZ, 'fx_unrealised_loss')->code);
        $this->assertSame('finance', AccountingPeriod::moduleFor('fx_revaluation'));
        $this->assertSame('finance', AccountingPeriod::moduleFor('fx_realised'));
    }
}
