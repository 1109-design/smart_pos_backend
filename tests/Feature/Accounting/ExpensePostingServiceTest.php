<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\ExpensePostingService;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpensePostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpensePostingService $posting;

    private string $businessId = 'biz-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->posting = app(ExpensePostingService::class);

        Tenant::create(['id' => $this->businessId, 'business_name' => $this->businessId, 'owner_email' => 'a@example.com']);
        Business::create(['id' => $this->businessId, 'name' => $this->businessId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->businessId);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->businessId)->where('code', $code)->firstOrFail();
    }

    /**
     * Cash/Bank/Mobile Money Clearing are all must_be_positive — see
     * SalaryPostingServiceTest's identical helper/comment.
     */
    private function fund(string $accountCode, float $amount): void
    {
        $journals = app(JournalService::class);
        $header = $journals->createDraft($this->businessId, '2026-01-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $this->account($accountCode)->id, 'debit' => $amount]);
        $journals->addLine($header, ['gl_account_id' => $this->account('3000')->id, 'credit' => $amount]);
        $journals->post($header);
    }

    private function makeExpense(float $amount, string $category = 'Rent', string $method = 'cash', ?string $bankAccountId = null): Expense
    {
        return Expense::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->businessId,
            'recorded_by_user_id' => (string) Str::uuid(),
            'category' => $category,
            'amount' => $amount,
            'currency_code' => 'USD',
            'base_equivalent' => $amount,
            'exchange_rate' => 1,
            'payment_method' => $method,
            'bank_account_id' => $bankAccountId,
            'expense_date' => '2026-08-15',
        ]);
    }

    public function test_a_zero_amount_expense_is_never_posted(): void
    {
        $expense = $this->makeExpense(0, 'Rent', 'cash');

        $this->posting->postIfReady($expense);

        $this->assertSame(0, JournalHeader::where('source_type', 'expense')->count());
    }
}
