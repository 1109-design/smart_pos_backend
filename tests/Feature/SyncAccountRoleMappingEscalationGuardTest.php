<?php

namespace Tests\Feature;

use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\GlAccount;
use App\Models\AccountRoleMapping;
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
 * Security audit follow-up, same class as SyncUserRoleEscalationGuardTest.
 * An account_role_mapping row decides which real GL account every future
 * sale/payment/posting for an entire category lands in (see
 * AccountRoleMappingService's doc comment) — the till only shows its edit
 * screen to UserRole.owner (settings_screen.dart), but that client-side
 * gate is bypassed entirely by a raw API call, and the generic device sync
 * path had no server-side check at all. Any authenticated device could
 * silently redirect where a whole revenue/expense category posts.
 */
class SyncAccountRoleMappingEscalationGuardTest extends TestCase
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

    private function makeGlAccount(string $tenantId, string $code): GlAccount
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

    private function pushMapping(string $token, string $tenantId, string $role, string $glAccountId): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'account_role_mappings',
                    'uuid' => 'mapping-'.$tenantId.'-'.$role,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'role' => $role,
                        'gl_account_id' => $glAccountId,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_a_cashier_cannot_redirect_where_a_revenue_category_posts(): void
    {
        $tenantId = 'tenant-mapping-escalation';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $legitimate = $this->makeGlAccount($tenantId, '4000');
        $fraudulent = $this->makeGlAccount($tenantId, '9999');
        AccountRoleMapping::create([
            'business_id' => $tenantId,
            'role' => 'cash_sales_revenue',
            'gl_account_id' => $legitimate->id,
        ]);

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $response = $this->pushMapping($token, $tenantId, 'cash_sales_revenue', $fraudulent->id);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'account_role_mappings: changing GL account routing requires the business owner role.',
            $response->json('errors.0.reason'),
        );
        $this->assertSame(
            $legitimate->id,
            AccountRoleMapping::where('business_id', $tenantId)->where('role', 'cash_sales_revenue')->value('gl_account_id'),
        );
    }

    public function test_a_manager_cannot_redirect_it_either_only_the_owner_can(): void
    {
        $tenantId = 'tenant-mapping-manager';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $legitimate = $this->makeGlAccount($tenantId, '4000');
        $other = $this->makeGlAccount($tenantId, '4100');
        AccountRoleMapping::create([
            'business_id' => $tenantId,
            'role' => 'cash_sales_revenue',
            'gl_account_id' => $legitimate->id,
        ]);

        $manager = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-manager@example.com']);
        $manager->assignRole('manager');
        $token = $this->actingDeviceToken($tenantId, $manager);

        $response = $this->pushMapping($token, $tenantId, 'cash_sales_revenue', $other->id);

        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            $legitimate->id,
            AccountRoleMapping::where('business_id', $tenantId)->where('role', 'cash_sales_revenue')->value('gl_account_id'),
        );
    }

    public function test_the_business_owner_can_legitimately_change_the_mapping(): void
    {
        $tenantId = 'tenant-mapping-owner';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $legitimate = $this->makeGlAccount($tenantId, '4000');
        $newAccount = $this->makeGlAccount($tenantId, '4200');
        AccountRoleMapping::create([
            'business_id' => $tenantId,
            'role' => 'cash_sales_revenue',
            'gl_account_id' => $legitimate->id,
        ]);

        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $response = $this->pushMapping($token, $tenantId, 'cash_sales_revenue', $newAccount->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertSame(
            $newAccount->id,
            AccountRoleMapping::where('business_id', $tenantId)->where('role', 'cash_sales_revenue')->value('gl_account_id'),
        );
    }

    public function test_a_device_resyncing_its_own_unchanged_mapping_is_not_blocked(): void
    {
        $tenantId = 'tenant-mapping-noop';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $legitimate = $this->makeGlAccount($tenantId, '4000');
        AccountRoleMapping::create([
            'business_id' => $tenantId,
            'role' => 'cash_sales_revenue',
            'gl_account_id' => $legitimate->id,
        ]);

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        // Same mapping as already stored — a routine resync, not an
        // escalation attempt.
        $response = $this->pushMapping($token, $tenantId, 'cash_sales_revenue', $legitimate->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
    }

    public function test_a_trusted_server_side_write_is_not_gated(): void
    {
        $tenantId = 'tenant-mapping-trusted';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $account = $this->makeGlAccount($tenantId, '4000');

        // Mirrors how AccountRoleMappingService's own BackOffice write path
        // works — server-authored, already access-controlled upstream.
        app(SyncProcessor::class)->process(
            'account_role_mappings',
            (string) Str::uuid(),
            'upsert',
            [
                'business_id' => $tenantId,
                'role' => 'cash_sales_revenue',
                'gl_account_id' => $account->id,
            ],
            trusted: true,
        );

        $this->assertSame(
            $account->id,
            AccountRoleMapping::where('business_id', $tenantId)->where('role', 'cash_sales_revenue')->value('gl_account_id'),
        );
    }
}
