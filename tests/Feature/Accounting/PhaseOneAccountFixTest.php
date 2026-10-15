<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\AccountRoleMapping;
use App\Models\Business;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Services\Accounting\AccountRoleMappingService;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1: the accounts the posting roles need exist, the three roles that
 * pointed at the wrong accounts default to the right ones, and existing
 * businesses' mappings are moved — unless an owner chose something else.
 */
class PhaseOneAccountFixTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $id): void
    {
        Tenant::create(['id' => $id, 'business_name' => $id, 'owner_email' => $id.'@example.com']);
        Business::create(['id' => $id, 'name' => $id, 'currency_code' => 'USD']);
        (new ChartOfAccountsSeeder)->seedForBusiness($id);
    }

    private function account(string $businessId, string $code): GlAccount
    {
        return GlAccount::where('business_id', $businessId)->where('code', $code)->firstOrFail();
    }

    private function mapTo(string $businessId, string $role, string $code): void
    {
        AccountRoleMapping::updateOrCreate(
            ['business_id' => $businessId, 'role' => $role],
            ['gl_account_id' => $this->account($businessId, $code)->id],
        );
    }

    private function runMigration(): void
    {
        (require database_path('migrations/2026_10_16_090000_fix_phase1_accounts_and_role_mappings.php'))->up();
    }

    private function mappedCode(string $businessId, string $role): string
    {
        $mapping = AccountRoleMapping::where('business_id', $businessId)->where('role', $role)->firstOrFail();

        return GlAccount::findOrFail($mapping->gl_account_id)->code;
    }

    public function test_a_new_chart_has_the_accounts_the_roles_need(): void
    {
        $this->business('biz-new');

        foreach (['2025' => 'Change Owed', '4050' => 'Sales Returns', '4060' => 'Sales Discounts', '6045' => 'Bad Debts'] as $code => $name) {
            $this->assertSame($name, $this->account('biz-new', $code)->name);
        }
    }

    public function test_the_roles_default_to_the_right_accounts(): void
    {
        $this->business('biz-roles');
        $roles = app(AccountRoleMappingService::class);

        $this->assertSame('6045', $roles->resolve('biz-roles', 'bad_debts')->code);
        $this->assertSame('4060', $roles->resolve('biz-roles', 'sales_discount')->code);
        $this->assertSame('4050', $roles->resolve('biz-roles', 'sales_returns')->code);
        $this->assertSame('2025', $roles->resolve('biz-roles', 'change_owed_liability')->code);
    }

    public function test_existing_wrong_mappings_are_moved_in_place_and_published(): void
    {
        $this->business('biz-old');
        $this->mapTo('biz-old', 'bad_debts', '6040');
        $this->mapTo('biz-old', 'sales_discount', '6065');
        $this->mapTo('biz-old', 'sales_returns', '4010');
        $ids = AccountRoleMapping::where('business_id', 'biz-old')->pluck('id', 'role');

        $this->runMigration();

        $this->assertSame('6045', $this->mappedCode('biz-old', 'bad_debts'));
        $this->assertSame('4060', $this->mappedCode('biz-old', 'sales_discount'));
        $this->assertSame('4050', $this->mappedCode('biz-old', 'sales_returns'));
        // Same rows devices already hold.
        $this->assertEquals($ids, AccountRoleMapping::where('business_id', 'biz-old')->pluck('id', 'role'));
        $this->assertSame(3, SyncRecord::where('business_id', 'biz-old')->where('table_name', 'account_role_mappings')->count());
    }

    public function test_an_owners_own_choice_is_left_alone(): void
    {
        $this->business('biz-chosen');
        $this->mapTo('biz-chosen', 'bad_debts', '6090');

        $this->runMigration();

        $this->assertSame('6090', $this->mappedCode('biz-chosen', 'bad_debts'));
    }

    public function test_a_chart_from_before_these_accounts_gets_them(): void
    {
        $this->business('biz-legacy');
        GlAccount::where('business_id', 'biz-legacy')->whereIn('code', ['2025', '4050', '4060', '6045'])->delete();

        $this->runMigration();

        foreach (['2025', '4050', '4060', '6045'] as $code) {
            $this->assertNotNull($this->account('biz-legacy', $code));
        }
    }
}
