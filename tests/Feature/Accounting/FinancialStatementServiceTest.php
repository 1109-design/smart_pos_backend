<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\FinancialStatementService;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialStatementServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialStatementService $statements;

    private JournalService $journals;

    private string $businessId = 'biz-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->statements = app(FinancialStatementService::class);
        $this->journals = app(JournalService::class);

        Tenant::create(['id' => $this->businessId, 'business_name' => $this->businessId, 'owner_email' => 'a@example.com']);
        Business::create(['id' => $this->businessId, 'name' => $this->businessId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->businessId);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->businessId)->where('code', $code)->firstOrFail();
    }

    private function postJournal(string $date, string $debitCode, string $creditCode, float $amount): void
    {
        $header = $this->journals->createDraft($this->businessId, $date, 'manual', (string) Str::uuid());
        $this->journals->addLine($header, ['gl_account_id' => $this->account($debitCode)->id, 'debit' => $amount]);
        $this->journals->addLine($header, ['gl_account_id' => $this->account($creditCode)->id, 'credit' => $amount]);
        $this->journals->post($header);
    }

    public function test_trial_balance_is_balanced_and_omits_zero_balance_accounts(): void
    {
        $this->postJournal('2026-06-01', '1000', '3000', 1000); // capital injection
        $this->postJournal('2026-06-05', '5000', '1200', 200);  // COGS from a sale

        $report = $this->statements->trialBalance($this->businessId, '2026-06-30');

        $this->assertTrue($report['is_balanced']);
        $this->assertSame(1200.0, $report['total_debit']);
        $this->assertSame(1200.0, $report['total_credit']);

        $codes = $report['accounts']->pluck('code')->all();
        $this->assertContains('1000', $codes);
        $this->assertContains('3000', $codes);
        $this->assertContains('5000', $codes);
        $this->assertContains('1200', $codes);
        $this->assertNotContains('6000', $codes, 'Rent has no activity and should not appear.');
    }

    public function test_trial_balance_as_of_date_excludes_later_activity(): void
    {
        $this->postJournal('2026-06-01', '1000', '3000', 1000);
        $this->postJournal('2026-07-15', '6000', '1000', 300); // rent paid, after the as-of date

        $report = $this->statements->trialBalance($this->businessId, '2026-06-30');

        $this->assertSame(1000.0, $this->accountRow($report['accounts'], '1000')['debit_balance']);
        $codes = $report['accounts']->pluck('code')->all();
        $this->assertNotContains('6000', $codes);
    }

    public function test_income_statement_computes_net_income_for_the_period(): void
    {
        $this->postJournal('2026-06-01', '1000', '4000', 500);   // a sale, cash in / revenue
        $this->postJournal('2026-06-01', '5000', '1200', 200);   // matching COGS
        $this->postJournal('2026-06-10', '6000', '1000', 50);    // rent expense

        $report = $this->statements->incomeStatement($this->businessId, '2026-06-01', '2026-06-30');

        $this->assertSame(500.0, $report['total_revenue']);
        $this->assertSame(200.0, $report['total_cost_of_sales']);
        $this->assertSame(50.0, $report['total_expenses']);
        $this->assertSame(250.0, $report['net_income']);
    }

    public function test_income_statement_excludes_activity_outside_the_date_range(): void
    {
        $this->postJournal('2026-05-01', '1000', '4000', 999); // before the range
        $this->postJournal('2026-06-15', '1000', '4000', 100); // inside the range
        $this->postJournal('2026-07-01', '1000', '4000', 999); // after the range

        $report = $this->statements->incomeStatement($this->businessId, '2026-06-01', '2026-06-30');

        $this->assertSame(100.0, $report['total_revenue']);
    }

    public function test_balance_sheet_balances_with_current_earnings_rolled_into_equity(): void
    {
        $this->postJournal('2026-06-01', '1000', '3000', 1000); // capital
        $this->postJournal('2026-06-05', '1000', '4000', 300);  // revenue received in cash
        $this->postJournal('2026-06-10', '6000', '1000', 80);   // rent paid

        $report = $this->statements->balanceSheet($this->businessId, '2026-06-30');

        $this->assertTrue($report['is_balanced']);
        $this->assertSame(1220.0, $report['total_assets']); // 1000 + 300 - 80 cash
        $this->assertSame(0.0, $report['total_liabilities']);
        $this->assertSame(1220.0, $report['total_equity']); // 1000 capital + 220 current earnings

        $equity = $report['sections']->firstWhere('category', 'Equity');
        $currentEarnings = collect($equity['lines'])->firstWhere('name', 'Current Earnings (unclosed)');
        $this->assertNotNull($currentEarnings);
        $this->assertSame(220.0, $currentEarnings['amount']);
    }

    public function test_balance_sheet_as_of_date_excludes_later_activity(): void
    {
        $this->postJournal('2026-06-01', '1000', '3000', 1000);
        $this->postJournal('2026-07-15', '1000', '4000', 500); // after the as-of date

        $report = $this->statements->balanceSheet($this->businessId, '2026-06-30');

        $this->assertSame(1000.0, $report['total_assets']);
        $this->assertTrue($report['is_balanced']);
    }

    public function test_a_business_with_no_postings_reports_all_zeros_and_balances_trivially(): void
    {
        $tenantId = 'biz-empty';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => 'b@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);

        $trialBalance = $this->statements->trialBalance($tenantId, '2026-06-30');
        $this->assertTrue($trialBalance['is_balanced']);
        $this->assertCount(0, $trialBalance['accounts']);

        $balanceSheet = $this->statements->balanceSheet($tenantId, '2026-06-30');
        $this->assertTrue($balanceSheet['is_balanced']);
        $this->assertSame(0.0, $balanceSheet['total_assets']);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accounts
     * @return array<string, mixed>
     */
    private function accountRow($accounts, string $code): array
    {
        return $accounts->firstWhere('code', $code);
    }
}
