<?php

namespace Tests\Feature;

use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\GlAccount;
use App\Models\BankAccount;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SyncProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Security audit follow-up, same class as SyncAccountRoleMappingEscalationGuardTest:
 * a bank account's gl_account_id decides which real GL account its activity
 * posts to. The till only shows the bank accounts screen to
 * Permission.manageCashVault (owner/manager by default), but that's a
 * client-side gate a raw API call bypasses entirely, and the generic device
 * sync path had no server-side check at all.
 */
class SyncBankAccountEscalationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingDeviceToken(string $tenantId, User $user): string
    {
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

    private function makeGlAccount(string $tenantId, string $code)
    {
        $category = AccountCategory::firstOrCreate(
            ['business_id' => $tenantId, 'name' => 'Assets'],
            ['is_debit_normal' => true, 'statement_type' => 'balance_sheet'],
        );

        return GlAccount::create([
            'business_id' => $tenantId,
            'code' => $code,
            'name' => "Account $code",
            'account_category_id' => $category->id,
        ]);
    }

    private function pushBankAccount(string $token, string $tenantId, string $bankAccountId, string $glAccountId): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'bank_accounts',
                    'uuid' => $bankAccountId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'name' => 'Main Checking',
                        'currency_code' => 'USD',
                        'gl_account_id' => $glAccountId,
                        'is_active' => true,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_a_cashier_cannot_redirect_an_existing_bank_accounts_gl_posting(): void
    {
        $tenantId = 'tenant-bank-escalation';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $legitimate = $this->makeGlAccount($tenantId, '1000');
        $fraudulent = $this->makeGlAccount($tenantId, '9999');
        $bankAccountId = (string) Str::uuid();
        BankAccount::create([
            'id' => $bankAccountId,
            'business_id' => $tenantId,
            'name' => 'Main Checking',
            'currency_code' => 'USD',
            'gl_account_id' => $legitimate->id,
            'is_active' => true,
        ]);

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $response = $this->pushBankAccount($token, $tenantId, $bankAccountId, $fraudulent->id);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'bank_accounts: changing which GL account a bank account posts to requires the owner or manager role.',
            $response->json('errors.0.reason'),
        );
        $this->assertSame($legitimate->id, BankAccount::find($bankAccountId)->gl_account_id);
    }

    public function test_a_manager_can_legitimately_change_it(): void
    {
        $tenantId = 'tenant-bank-manager';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $legitimate = $this->makeGlAccount($tenantId, '1000');
        $newAccount = $this->makeGlAccount($tenantId, '1100');
        $bankAccountId = (string) Str::uuid();
        BankAccount::create([
            'id' => $bankAccountId,
            'business_id' => $tenantId,
            'name' => 'Main Checking',
            'currency_code' => 'USD',
            'gl_account_id' => $legitimate->id,
            'is_active' => true,
        ]);

        $manager = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-manager@example.com']);
        $manager->assignRole('manager');
        $token = $this->actingDeviceToken($tenantId, $manager);

        $response = $this->pushBankAccount($token, $tenantId, $bankAccountId, $newAccount->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertSame($newAccount->id, BankAccount::find($bankAccountId)->gl_account_id);
    }

    public function test_a_cashier_can_create_a_brand_new_bank_account(): void
    {
        $tenantId = 'tenant-bank-create';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $account = $this->makeGlAccount($tenantId, '1000');

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $bankAccountId = (string) Str::uuid();
        $response = $this->pushBankAccount($token, $tenantId, $bankAccountId, $account->id);

        // Not gated: creation isn't itself the money-redirect vector this
        // guard targets. Documents current, deliberately narrow scope.
        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
    }

    public function test_a_trusted_server_side_write_is_not_gated(): void
    {
        $tenantId = 'tenant-bank-trusted';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $legitimate = $this->makeGlAccount($tenantId, '1000');
        $newAccount = $this->makeGlAccount($tenantId, '1100');
        $bankAccountId = (string) Str::uuid();
        BankAccount::create([
            'id' => $bankAccountId,
            'business_id' => $tenantId,
            'name' => 'Main Checking',
            'currency_code' => 'USD',
            'gl_account_id' => $legitimate->id,
            'is_active' => true,
        ]);

        app(SyncProcessor::class)->process(
            'bank_accounts',
            $bankAccountId,
            'upsert',
            [
                'business_id' => $tenantId,
                'name' => 'Main Checking',
                'currency_code' => 'USD',
                'gl_account_id' => $newAccount->id,
                'is_active' => true,
            ],
            trusted: true,
        );

        $this->assertSame($newAccount->id, BankAccount::find($bankAccountId)->gl_account_id);
    }
}
