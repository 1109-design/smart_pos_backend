<?php

namespace Tests\Feature;

use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\PartyLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A `credit_transactions` row of type 'opening_balance', pushed from the
 * till like any other credit ledger entry, should (a) always update the
 * customer's till-side credit_balance — the generic path every other type
 * already exercises — and (b) additionally post to the formal books via
 * OpeningBalanceService when this business has accounting switched on.
 */
class SyncCustomerOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        $user = User::factory()->create(['email' => $tenantId.'-owner@example.com']);
        $plain = $user->createToken('sync-test')->plainTextToken;
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

    private function account(string $businessId, string $code): GlAccount
    {
        return GlAccount::where('business_id', $businessId)->where('code', $code)->firstOrFail();
    }

    public function test_opening_balance_updates_till_balance_and_posts_to_the_ledger_when_accounting_is_live(): void
    {
        $tenantId = 'tenant-opening-balance-live';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $token = $this->actingDeviceToken($tenantId);

        $customerId = (string) Str::uuid();
        Customer::create(['id' => $customerId, 'business_id' => $tenantId, 'name' => 'Jane Doe']);

        $txnId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'credit_transactions',
                    'uuid' => $txnId,
                    'operation' => 'upsert',
                    'payload' => [
                        'customer_id' => $customerId,
                        'transaction_id' => null,
                        'amount' => 150,
                        'type' => 'opening_balance',
                        'method' => null,
                        'reference' => 'Carried forward from old ledger book',
                        'created_at' => '2026-01-05T00:00:00Z',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');

        $this->assertDatabaseHas('customers', ['id' => $customerId, 'credit_balance' => 150]);

        $ledger = app(PartyLedgerService::class);
        $this->assertSame(150.0, $ledger->currentBalance($tenantId, 'customer', $customerId));
        $this->assertSame(150.0, $this->account($tenantId, '1100')->balance());
    }

    public function test_opening_balance_updates_till_balance_only_when_accounting_is_not_live(): void
    {
        $tenantId = 'tenant-opening-balance-not-live';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $token = $this->actingDeviceToken($tenantId);

        $customerId = (string) Str::uuid();
        Customer::create(['id' => $customerId, 'business_id' => $tenantId, 'name' => 'Jane Doe']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'credit_transactions',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'customer_id' => $customerId,
                        'amount' => 150,
                        'type' => 'opening_balance',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'credit_balance' => 150]);
        $this->assertSame(0.0, $this->account($tenantId, '1100')->balance());
    }
}
