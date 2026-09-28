<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Accounting\JournalLine;
use App\Models\AccountRoleMapping;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\ChartOfAccountsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChartOfAccountsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLiveBusiness(string $id = 'biz-1'): string
    {
        Tenant::create(['id' => $id, 'business_name' => $id, 'owner_email' => "{$id}@example.com"]);
        Business::create(['id' => $id, 'name' => $id, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($id);

        return $id;
    }

    private function service(): ChartOfAccountsService
    {
        return app(ChartOfAccountsService::class);
    }

    public function test_a_new_category_gets_the_next_free_thousand_block(): void
    {
        $businessId = $this->makeLiveBusiness();

        $category = $this->service()->createCategory($businessId, 'Other Income', false, 'income_statement');

        $this->assertSame(7000, $category->code);
        $this->assertFalse($category->is_system);
        $this->assertDatabaseHas('sync_records', [
            'business_id' => $businessId, 'table_name' => 'account_categories', 'record_uuid' => $category->id,
        ]);
    }

    public function test_a_second_new_category_gets_the_next_block_after_the_first(): void
    {
        $businessId = $this->makeLiveBusiness();
        $this->service()->createCategory($businessId, 'Other Income', false, 'income_statement');

        $second = $this->service()->createCategory($businessId, 'Contra Revenue', false, 'income_statement');

        $this->assertSame(8000, $second->code);
    }

    public function test_a_new_account_is_assigned_a_code_in_its_categorys_reserved_user_range(): void
    {
        $businessId = $this->makeLiveBusiness();
        $assets = AccountCategory::where('business_id', $businessId)->where('code', 1000)->firstOrFail();

        $account = $this->service()->createAccount($businessId, $assets, null, 'Petty Cash Tin');

        // Assets block starts at 1000 — user accounts reserved at +900..+999.
        $this->assertSame('1900', $account->code);
        $this->assertSame('active', $account->status);
        $this->assertTrue($account->allow_direct_posting);
    }

    public function test_a_second_new_account_in_the_same_category_gets_the_next_code(): void
    {
        $businessId = $this->makeLiveBusiness();
        $assets = AccountCategory::where('business_id', $businessId)->where('code', 1000)->firstOrFail();
        $this->service()->createAccount($businessId, $assets, null, 'Petty Cash Tin');

        $second = $this->service()->createAccount($businessId, $assets, null, 'Float Cash');

        $this->assertSame('1901', $second->code);
    }

    public function test_updating_an_account_never_changes_its_code(): void
    {
        $businessId = $this->makeLiveBusiness();
        $assets = AccountCategory::where('business_id', $businessId)->where('code', 1000)->firstOrFail();
        $liabilities = AccountCategory::where('business_id', $businessId)->where('code', 2000)->firstOrFail();
        $account = $this->service()->createAccount($businessId, $assets, null, 'Petty Cash Tin');
        $originalCode = $account->code;

        $this->service()->updateAccount($account, 'Renamed Tin', $liabilities, null, null, true, false);

        $fresh = $account->fresh();
        $this->assertSame($originalCode, $fresh->code);
        $this->assertSame('Renamed Tin', $fresh->name);
        $this->assertSame($liabilities->id, $fresh->account_category_id);
    }

    public function test_deactivating_an_account_with_a_nonzero_balance_is_blocked(): void
    {
        $businessId = $this->makeLiveBusiness();
        $account = GlAccount::where('business_id', $businessId)->where('code', '6090')->firstOrFail();
        $header = JournalHeader::create([
            'business_id' => $businessId, 'journal_number' => 'JNL-TEST-1', 'trans_date' => now()->toDateString(),
            'source_type' => 'manual', 'source_id' => (string) Str::uuid(), 'status' => 'posted',
        ]);
        JournalLine::create(['journal_header_id' => $header->id, 'gl_account_id' => $account->id, 'debit' => 50, 'credit' => 0]);
        GeneralLedgerEntry::create([
            'business_id' => $businessId, 'journal_header_id' => $header->id, 'gl_account_id' => $account->id,
            'trans_date' => now()->toDateString(), 'debit' => 50, 'credit' => 0, 'status' => 'active',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('still has a balance');

        $this->service()->deactivateAccount($account);
    }

    public function test_deactivating_an_account_mapped_to_a_posting_role_is_blocked(): void
    {
        $businessId = $this->makeLiveBusiness();
        $account = GlAccount::where('business_id', $businessId)->where('code', '6090')->firstOrFail();
        AccountRoleMapping::create(['business_id' => $businessId, 'role' => 'salary_expense', 'gl_account_id' => $account->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("mapped to the 'salary_expense' posting role");

        $this->service()->deactivateAccount($account);
    }

    public function test_deactivating_an_account_a_live_bank_account_posts_to_is_blocked(): void
    {
        $businessId = $this->makeLiveBusiness();
        $account = GlAccount::where('business_id', $businessId)->where('code', '1010')->firstOrFail();
        BankAccount::create(['business_id' => $businessId, 'name' => 'Main Bank', 'gl_account_id' => $account->id, 'is_active' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("bank account 'Main Bank' still posts to it");

        $this->service()->deactivateAccount($account);
    }

    public function test_deactivating_an_unused_account_succeeds_and_it_can_be_reactivated(): void
    {
        $businessId = $this->makeLiveBusiness();
        $assets = AccountCategory::where('business_id', $businessId)->where('code', 1000)->firstOrFail();
        $account = $this->service()->createAccount($businessId, $assets, null, 'Petty Cash Tin');

        $this->service()->deactivateAccount($account);
        $this->assertSame('inactive', $account->fresh()->status);

        $this->service()->reactivateAccount($account->fresh());
        $this->assertSame('active', $account->fresh()->status);
    }
}
