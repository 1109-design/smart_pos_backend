<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\GlAccount;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartOfAccountsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_the_six_standard_categories_with_correct_normal_sides(): void
    {
        Tenant::create(['id' => 'biz-1', 'business_name' => 'biz-1', 'owner_email' => 'biz-1@example.com']);

        (new ChartOfAccountsSeeder)->seedForBusiness('biz-1');

        $categories = AccountCategory::where('business_id', 'biz-1')->orderBy('code')->get();

        $this->assertSame(
            ['Assets', 'Liabilities', 'Equity', 'Revenue', 'Cost of Sales', 'Expenses'],
            $categories->pluck('name')->all()
        );

        $this->assertTrue($categories->firstWhere('name', 'Assets')->is_debit_normal);
        $this->assertFalse($categories->firstWhere('name', 'Liabilities')->is_debit_normal);
        $this->assertFalse($categories->firstWhere('name', 'Equity')->is_debit_normal);
        $this->assertFalse($categories->firstWhere('name', 'Revenue')->is_debit_normal);
        $this->assertTrue($categories->firstWhere('name', 'Cost of Sales')->is_debit_normal);
        $this->assertTrue($categories->firstWhere('name', 'Expenses')->is_debit_normal);
    }

    public function test_control_accounts_and_positive_only_accounts_are_flagged(): void
    {
        Tenant::create(['id' => 'biz-1', 'business_name' => 'biz-1', 'owner_email' => 'biz-1@example.com']);
        (new ChartOfAccountsSeeder)->seedForBusiness('biz-1');

        $receivable = GlAccount::where('business_id', 'biz-1')->where('code', '1100')->first();
        $payable = GlAccount::where('business_id', 'biz-1')->where('code', '2000')->first();
        $inventory = GlAccount::where('business_id', 'biz-1')->where('code', '1200')->first();
        $cash = GlAccount::where('business_id', 'biz-1')->where('code', '1000')->first();

        $this->assertSame('receivable', $receivable->control_type);
        $this->assertSame('payable', $payable->control_type);
        $this->assertSame('inventory', $inventory->control_type);
        $this->assertTrue($cash->must_be_positive);
    }

    public function test_account_codes_are_unique_per_business(): void
    {
        Tenant::create(['id' => 'biz-1', 'business_name' => 'biz-1', 'owner_email' => 'biz-1@example.com']);
        (new ChartOfAccountsSeeder)->seedForBusiness('biz-1');

        $codes = GlAccount::where('business_id', 'biz-1')->pluck('code');

        $this->assertSame($codes->count(), $codes->unique()->count());
    }

    public function test_seeding_twice_for_the_same_business_is_a_no_op(): void
    {
        Tenant::create(['id' => 'biz-1', 'business_name' => 'biz-1', 'owner_email' => 'biz-1@example.com']);
        $seeder = new ChartOfAccountsSeeder;

        $seeder->seedForBusiness('biz-1');
        $countAfterFirst = GlAccount::where('business_id', 'biz-1')->count();

        $seeder->seedForBusiness('biz-1');
        $countAfterSecond = GlAccount::where('business_id', 'biz-1')->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_two_businesses_get_independent_charts(): void
    {
        Tenant::create(['id' => 'biz-a', 'business_name' => 'biz-a', 'owner_email' => 'a@example.com']);
        Tenant::create(['id' => 'biz-b', 'business_name' => 'biz-b', 'owner_email' => 'b@example.com']);
        $seeder = new ChartOfAccountsSeeder;

        $seeder->seedForBusiness('biz-a');

        $this->assertGreaterThan(0, GlAccount::where('business_id', 'biz-a')->count());
        $this->assertSame(0, GlAccount::where('business_id', 'biz-b')->count());
    }
}
