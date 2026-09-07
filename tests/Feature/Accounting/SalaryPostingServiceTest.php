<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\SalaryPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalaryPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private SalaryPostingService $posting;

    private string $businessId = 'biz-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->posting = app(SalaryPostingService::class);

        Tenant::create(['id' => $this->businessId, 'business_name' => $this->businessId, 'owner_email' => 'a@example.com']);
        Business::create(['id' => $this->businessId, 'name' => $this->businessId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->businessId);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->businessId)->where('code', $code)->firstOrFail();
    }

    /**
     * Cash/Bank/Mobile Money Clearing are all must_be_positive — a real
     * business only ever pays salaries out of funds a prior sale or capital
     * injection already put there, so every test that credits one of these
     * needs to fund it first, same as AssetPostingServiceTest does for asset
     * acquisitions.
     */
    private function fund(string $accountCode, float $amount): void
    {
        $journals = app(JournalService::class);
        $header = $journals->createDraft($this->businessId, '2026-01-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $this->account($accountCode)->id, 'debit' => $amount]);
        $journals->addLine($header, ['gl_account_id' => $this->account('3000')->id, 'credit' => $amount]);
        $journals->post($header);
    }

    private function makePayment(float $baseEquivalent, string $method = 'cash', ?string $date = null): SalaryPayment
    {
        $employee = Employee::create([
            'id' => (string) Str::uuid(), 'business_id' => $this->businessId, 'name' => 'Jane Worker',
        ]);

        $payment = SalaryPayment::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->businessId,
            'employee_id' => $employee->id,
            'period' => '2026-08',
            'amount' => $baseEquivalent,
            'currency_code' => 'USD',
            'base_equivalent' => $baseEquivalent,
            'exchange_rate' => 1,
            'payment_method' => $method,
            'paid_by_user_id' => (string) Str::uuid(),
            'paid_at' => $date ?? now(),
        ]);

        if ($date) {
            DB::table('salary_payments')->where('id', $payment->id)->update(['paid_at' => $date]);
            $payment->refresh();
        }

        return $payment;
    }

    public function test_a_cash_salary_payment_debits_wages_and_credits_cash(): void
    {
        $this->fund('1000', 1000);
        $payment = $this->makePayment(250, 'cash');

        $this->posting->recordPayment($payment);

        $journal = JournalHeader::where('source_type', 'salary_payment')->where('source_id', $payment->id)->first();
        $this->assertNotNull($journal);
        $this->assertSame('posted', $journal->status);
        $this->assertSame(250.0, $this->account('6020')->balance()); // Wages debited
        $this->assertSame(750.0, $this->account('1000')->balance()); // Cash credited: 1000 - 250
    }

    public function test_a_bank_transfer_salary_payment_credits_bank(): void
    {
        $this->fund('1010', 1000);
        $payment = $this->makePayment(300, 'bank_transfer');

        $this->posting->recordPayment($payment);

        $this->assertSame(700.0, $this->account('1010')->balance());
    }

    public function test_a_cheque_salary_payment_credits_bank(): void
    {
        $this->fund('1010', 1000);
        $payment = $this->makePayment(300, 'cheque');

        $this->posting->recordPayment($payment);

        $this->assertSame(700.0, $this->account('1010')->balance());
    }

    public function test_a_mobile_money_salary_payment_credits_mobile_money_clearing(): void
    {
        $this->fund('1020', 1000);
        $payment = $this->makePayment(180, 'mobile_money');

        $this->posting->recordPayment($payment);

        $this->assertSame(820.0, $this->account('1020')->balance());
    }

    public function test_processing_the_same_payment_twice_does_not_double_post(): void
    {
        $this->fund('1000', 1000);
        $payment = $this->makePayment(250, 'cash');

        $this->posting->recordPayment($payment);
        $this->posting->recordPayment($payment);

        $this->assertSame(1, JournalHeader::where('source_type', 'salary_payment')->count());
        $this->assertSame(250.0, $this->account('6020')->balance());
    }

    public function test_a_zero_amount_payment_is_never_posted(): void
    {
        $payment = $this->makePayment(0, 'cash');

        $this->posting->recordPayment($payment);

        $this->assertSame(0, JournalHeader::where('source_type', 'salary_payment')->count());
    }

    public function test_nothing_posts_before_the_accounting_go_live_date(): void
    {
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => '2026-07-01']);
        $this->fund('1000', 1000);
        $payment = $this->makePayment(250, 'cash', date: '2026-06-01 09:00:00');

        $this->posting->recordPayment($payment);

        $this->assertSame(0, JournalHeader::where('source_type', 'salary_payment')->count());
    }
}
