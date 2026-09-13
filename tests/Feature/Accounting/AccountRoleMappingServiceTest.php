<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\AccountRoleMapping;
use App\Models\Business;
use App\Models\Tenant;
use App\Services\Accounting\AccountRoleMappingService;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountRoleMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLiveBusiness(string $id = 'biz-1'): string
    {
        Tenant::create(['id' => $id, 'business_name' => $id, 'owner_email' => "{$id}@example.com"]);
        Business::create(['id' => $id, 'name' => $id, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($id);

        return $id;
    }

    public function test_resolving_a_role_for_the_first_time_finds_the_already_seeded_default_account(): void
    {
        $businessId = $this->makeLiveBusiness();

        $account = app(AccountRoleMappingService::class)->resolve($businessId, 'salary_expense');

        $this->assertSame('6020', $account->code);
        $this->assertSame('Wages', $account->name);
        $this->assertDatabaseHas('account_role_mappings', [
            'business_id' => $businessId,
            'role' => 'salary_expense',
            'gl_account_id' => $account->id,
        ]);
    }

    public function test_resolving_a_role_twice_returns_the_same_account_without_creating_a_duplicate_mapping(): void
    {
        $businessId = $this->makeLiveBusiness();
        $service = app(AccountRoleMappingService::class);

        $first = $service->resolve($businessId, 'accounts_payable');
        $second = $service->resolve($businessId, 'accounts_payable');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AccountRoleMapping::where('business_id', $businessId)->where('role', 'accounts_payable')->count());
    }

    public function test_reassigning_a_role_changes_what_resolve_returns(): void
    {
        $businessId = $this->makeLiveBusiness();
        $service = app(AccountRoleMappingService::class);
        $service->resolve($businessId, 'default_bank');

        $customAccount = GlAccount::where('business_id', $businessId)->where('code', '1005')->firstOrFail();
        $service->setMapping($businessId, 'default_bank', $customAccount->id);

        $resolved = $service->resolve($businessId, 'default_bank');
        $this->assertSame($customAccount->id, $resolved->id);
    }

    public function test_resolving_an_unknown_role_throws(): void
    {
        $businessId = $this->makeLiveBusiness();

        $this->expectException(\RuntimeException::class);
        app(AccountRoleMappingService::class)->resolve($businessId, 'not_a_real_role');
    }

    public function test_each_business_has_its_own_independent_mapping(): void
    {
        $businessA = $this->makeLiveBusiness('biz-a');
        $businessB = $this->makeLiveBusiness('biz-b');
        $service = app(AccountRoleMappingService::class);

        $accountA = GlAccount::where('business_id', $businessA)->where('code', '1005')->firstOrFail();
        $service->setMapping($businessA, 'default_bank', $accountA->id);

        $resolvedA = $service->resolve($businessA, 'default_bank');
        $resolvedB = $service->resolve($businessB, 'default_bank');

        $this->assertSame($accountA->id, $resolvedA->id);
        $this->assertNotSame($accountA->id, $resolvedB->id);
        $this->assertSame('1010', $resolvedB->code);
    }

    public function test_known_roles_lists_every_resolvable_role(): void
    {
        $roles = app(AccountRoleMappingService::class)->knownRoles();

        $this->assertContains('salary_expense', $roles);
        $this->assertContains('accounts_payable', $roles);
        $this->assertContains('fixed_assets', $roles);
        $this->assertContains('disposal_gain_loss', $roles);
        $this->assertContains('default_cash', $roles);
        $this->assertContains('default_bank', $roles);
        $this->assertContains('default_mobile_money', $roles);
    }
}
