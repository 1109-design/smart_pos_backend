<?php

namespace Tests\Feature;

use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\AccountSubCategory;
use App\Models\Accounting\GlAccount;
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
 * Security audit follow-up, same class as SyncAccountRoleMappingEscalationGuardTest.
 * gl_accounts/account_sub_categories are an ordinarily server-only table
 * (chart of accounts is seeded once, see ChartOfAccountsSeeder) that the
 * generic device sync path accepted from any authenticated till with no
 * server-side check at all — a device could push a brand-new account, or
 * silently rewrite an existing one's code/category/control_type/status by
 * reusing its known id. Gated the same way bank_accounts already is:
 * owner or manager required (Permission.manageCashVault's own floor, since
 * gl_accounts creation is also reachable via bank-account creation and
 * must keep working for a manager, not just an owner).
 */
class SyncChartOfAccountsEscalationGuardTest extends TestCase
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

    private function makeCategory(string $tenantId, string $name = 'Assets'): AccountCategory
    {
        return AccountCategory::firstOrCreate(
            ['business_id' => $tenantId, 'name' => $name],
            ['is_debit_normal' => true, 'statement_type' => 'balance_sheet'],
        );
    }

    private function makeGlAccount(string $tenantId, string $code, ?string $categoryId = null): GlAccount
    {
        return GlAccount::create([
            'business_id' => $tenantId,
            'code' => $code,
            'name' => "Account $code",
            'account_category_id' => $categoryId ?? $this->makeCategory($tenantId)->id,
        ]);
    }

    private function pushGlAccount(string $token, string $uuid, array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'gl_accounts',
                    'uuid' => $uuid,
                    'operation' => 'upsert',
                    'payload' => $payload,
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    private function pushSubCategory(string $token, string $uuid, array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'account_sub_categories',
                    'uuid' => $uuid,
                    'operation' => 'upsert',
                    'payload' => $payload,
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_a_cashier_cannot_rewrite_an_existing_gl_account(): void
    {
        $tenantId = 'tenant-coa-escalation';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $account = $this->makeGlAccount($tenantId, '4000');
        $otherCategory = $this->makeCategory($tenantId, 'Liabilities');

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $response = $this->pushGlAccount($token, $account->id, [
            'business_id' => $tenantId,
            'code' => $account->code,
            'name' => $account->name,
            'account_category_id' => $otherCategory->id,
        ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'gl_accounts: creating or changing a chart-of-accounts entry requires the owner or manager role.',
            $response->json('errors.0.reason'),
        );
        $this->assertSame($account->account_category_id, $account->fresh()->account_category_id);
    }

    public function test_a_cashier_cannot_push_a_brand_new_gl_account(): void
    {
        $tenantId = 'tenant-coa-new-account';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $category = $this->makeCategory($tenantId);

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $fabricatedId = (string) Str::uuid();
        $response = $this->pushGlAccount($token, $fabricatedId, [
            'business_id' => $tenantId,
            'code' => '9999',
            'name' => 'Fabricated Account',
            'account_category_id' => $category->id,
        ]);

        $this->assertCount(0, $response->json('accepted'));
        $this->assertDatabaseMissing('gl_accounts', ['id' => $fabricatedId]);
    }

    public function test_a_manager_can_legitimately_create_a_gl_account(): void
    {
        $tenantId = 'tenant-coa-manager';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $category = $this->makeCategory($tenantId);

        $manager = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-manager@example.com']);
        $manager->assignRole('manager');
        $token = $this->actingDeviceToken($tenantId, $manager);

        // Mirrors bank_account_service.dart minting a new GL account while
        // creating a bank account — a manager must keep being able to do
        // this, same as Permission.manageCashVault already allows.
        $newId = (string) Str::uuid();
        $response = $this->pushGlAccount($token, $newId, [
            'business_id' => $tenantId,
            'code' => '1011',
            'name' => 'Operating Account',
            'account_category_id' => $category->id,
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertDatabaseHas('gl_accounts', ['id' => $newId, 'code' => '1011']);
    }

    public function test_a_device_resyncing_its_own_unchanged_gl_account_is_not_blocked(): void
    {
        $tenantId = 'tenant-coa-noop';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $account = $this->makeGlAccount($tenantId, '4000');

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $response = $this->pushGlAccount($token, $account->id, [
            'business_id' => $tenantId,
            'code' => $account->code,
            'name' => $account->name,
            'account_category_id' => $account->account_category_id,
            'account_sub_category_id' => $account->account_sub_category_id,
            'allow_direct_posting' => $account->allow_direct_posting,
            'control_type' => $account->control_type,
            'must_be_positive' => $account->must_be_positive,
            'status' => $account->status,
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
    }

    public function test_a_cashier_cannot_rewrite_an_existing_sub_category(): void
    {
        $tenantId = 'tenant-coa-subcat-escalation';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $category = $this->makeCategory($tenantId);
        $otherCategory = $this->makeCategory($tenantId, 'Liabilities');

        $subCategory = AccountSubCategory::create([
            'business_id' => $tenantId,
            'account_category_id' => $category->id,
            'name' => 'Bank Accounts',
            'reporting_order' => 1,
        ]);

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $response = $this->pushSubCategory($token, $subCategory->id, [
            'business_id' => $tenantId,
            'account_category_id' => $otherCategory->id,
            'name' => $subCategory->name,
            'reporting_order' => $subCategory->reporting_order,
        ]);

        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame($category->id, $subCategory->fresh()->account_category_id);
    }

    public function test_a_cashier_cannot_push_a_brand_new_account_category(): void
    {
        $tenantId = 'tenant-coa-new-category';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $fabricatedId = (string) Str::uuid();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'account_categories',
                    'uuid' => $fabricatedId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'name' => 'Fabricated Category',
                        'is_debit_normal' => true,
                        'statement_type' => 'balance_sheet',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $this->assertCount(0, $response->json('accepted'));
        $this->assertDatabaseMissing('account_categories', ['id' => $fabricatedId]);
    }

    public function test_an_owner_can_legitimately_create_a_new_account_category(): void
    {
        $tenantId = 'tenant-coa-owner-category';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $newId = (string) Str::uuid();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'account_categories',
                    'uuid' => $newId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'name' => 'Other Income',
                        'is_debit_normal' => false,
                        'statement_type' => 'income_statement',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertDatabaseHas('account_categories', ['id' => $newId, 'name' => 'Other Income']);
    }

    public function test_a_trusted_server_side_write_is_not_gated(): void
    {
        $tenantId = 'tenant-coa-trusted';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $category = $this->makeCategory($tenantId);

        // Mirrors ChartOfAccountsSeeder's own write path — server-authored,
        // already access-controlled upstream.
        $newId = (string) Str::uuid();
        app(SyncProcessor::class)->process(
            'gl_accounts',
            $newId,
            'upsert',
            [
                'business_id' => $tenantId,
                'code' => '5000',
                'name' => 'Cost of Sales',
                'account_category_id' => $category->id,
            ],
            trusted: true,
        );

        $this->assertDatabaseHas('gl_accounts', ['id' => $newId, 'code' => '5000']);
    }
}
