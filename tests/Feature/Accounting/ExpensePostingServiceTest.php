<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Tenant;
use App\Services\Accounting\BankAccountService;
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

    public function test_a_cash_expense_debits_the_matching_category_account_and_credits_cash(): void
    {
        $this->fund('1000', 1000);
        $expense = $this->makeExpense(250, 'Rent', 'cash');

        $this->posting->postIfReady($expense);

        $journal = JournalHeader::where('source_type', 'expense')->where('source_id', $expense->id)->first();
        $this->assertNotNull($journal);
        $this->assertSame('posted', $journal->status);
        $this->assertSame(250.0, $this->account('6000')->balance()); // Rent debited
        $this->assertSame(750.0, $this->account('1000')->balance()); // Cash credited
    }

    public function test_a_card_expense_with_no_bank_account_specified_credits_the_generic_bank_account(): void
    {
        $this->fund('1010', 1000);
        $expense = $this->makeExpense(300, 'Transport', 'card');

        $this->posting->postIfReady($expense);

        $this->assertSame(700.0, $this->account('1010')->balance());
        $this->assertSame(300.0, $this->account('6010')->balance()); // Transport debited
    }

    public function test_a_card_expense_tagged_with_a_specific_bank_account_posts_against_that_accounts_own_gl_line(): void
    {
        $bankAccount = app(BankAccountService::class)->create($this->businessId, 'CBZ Main Account');
        $namedBankGl = GlAccount::find($bankAccount->gl_account_id);
        $journals = app(JournalService::class);
        $header = $journals->createDraft($this->businessId, '2026-01-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $namedBankGl->id, 'debit' => 1000]);
        $journals->addLine($header, ['gl_account_id' => $this->account('3000')->id, 'credit' => 1000]);
        $journals->post($header);

        $expense = $this->makeExpense(300, 'Transport', 'card', $bankAccount->id);

        $this->posting->postIfReady($expense);

        $this->assertSame(0.0, $this->account('1010')->balance());
        $this->assertSame(700.0, $namedBankGl->fresh()->balance());
    }

    public function test_a_mobile_money_expense_credits_mobile_money_clearing(): void
    {
        $this->fund('1020', 1000);
        $expense = $this->makeExpense(80, 'Rent', 'mobile_money');

        $this->posting->postIfReady($expense);

        $this->assertSame(920.0, $this->account('1020')->balance());
    }

    public function test_a_category_with_no_matching_gl_account_falls_back_to_general_expenses(): void
    {
        $this->fund('1000', 1000);
        $expense = $this->makeExpense(60, 'Stock / Supplies', 'cash');

        $this->posting->postIfReady($expense);

        $this->assertSame(60.0, $this->account('6090')->balance()); // General Expenses
    }

    public function test_a_payroll_generated_expense_row_is_never_posted(): void
    {
        $this->fund('1000', 1000);
        $expense = $this->makeExpense(500, 'Salary', 'cash');

        $this->posting->postIfReady($expense);

        $this->assertSame(0, JournalHeader::where('source_type', 'expense')->count());
    }

    public function test_a_zero_amount_expense_is_never_posted(): void
    {
        $expense = $this->makeExpense(0, 'Rent', 'cash');

        $this->posting->postIfReady($expense);

        $this->assertSame(0, JournalHeader::where('source_type', 'expense')->count());
    }

    public function test_processing_the_same_expense_twice_does_not_double_post(): void
    {
        $this->fund('1000', 1000);
        $expense = $this->makeExpense(250, 'Rent', 'cash');

        $this->posting->postIfReady($expense);
        $this->posting->postIfReady($expense);

        $this->assertSame(1, JournalHeader::where('source_type', 'expense')->count());
        $this->assertSame(250.0, $this->account('6000')->balance());
    }

    public function test_editing_a_posted_expense_reverses_the_old_entry_and_posts_a_fresh_one(): void
    {
        $this->fund('1000', 1000);
        $expense = $this->makeExpense(100, 'Rent', 'cash');
        $this->posting->postIfReady($expense);
        $firstHeader = JournalHeader::where('source_type', 'expense')->where('source_id', $expense->id)->where('status', 'posted')->first();

        $expense->update(['amount' => 150, 'base_equivalent' => 150]);
        $this->posting->postIfReady($expense);

        $this->assertSame('reversed', $firstHeader->fresh()->status);
        $newHeader = JournalHeader::where('source_type', 'expense')->where('source_id', $expense->id)->where('status', 'posted')->first();
        $this->assertNotNull($newHeader);
        $this->assertNotSame($firstHeader->id, $newHeader->id);
        $this->assertSame(150.0, $this->account('6000')->balance());
    }

    public function test_cancelling_a_posted_expense_reverses_it(): void
    {
        $this->fund('1000', 1000);
        $expense = $this->makeExpense(100, 'Rent', 'cash');
        $this->posting->postIfReady($expense);
        $header = JournalHeader::where('source_type', 'expense')->where('source_id', $expense->id)->first();

        $expense->update(['deleted_at' => now()]);
        $this->posting->postIfReady($expense->fresh());

        $this->assertSame('reversed', $header->fresh()->status);
        $this->assertSame(0.0, $this->account('6000')->balance());
    }

    public function test_nothing_posts_before_the_accounting_go_live_date(): void
    {
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => '2026-07-01']);
        $this->fund('1000', 1000);
        $expense = $this->makeExpense(250, 'Rent', 'cash');
        $expense->update(['expense_date' => '2026-06-01']);

        $this->posting->postIfReady($expense->fresh());

        $this->assertSame(0, JournalHeader::where('source_type', 'expense')->count());
    }

    public function test_once_client_gl_posting_is_enabled_the_server_defers_unless_via_sweep(): void
    {
        Business::where('id', $this->businessId)->update(['client_gl_posting_enabled_at' => '2026-08-01']);
        $this->fund('1000', 1000);
        $expense = $this->makeExpense(250, 'Rent', 'cash');
        $expense->update(['expense_date' => '2026-08-15']);
        $expense = $expense->fresh();

        $this->posting->postIfReady($expense);
        $this->assertSame(0, JournalHeader::where('source_type', 'expense')->count());

        $this->posting->postIfReady($expense, viaSweep: true);
        $this->assertSame(1, JournalHeader::where('source_type', 'expense')->count());
    }
}
