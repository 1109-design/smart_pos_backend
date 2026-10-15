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

    public function test_a_zero_amount_payment_is_never_posted(): void
    {
        $payment = $this->makePayment(0, 'cash');

        $this->posting->recordPayment($payment);

        $this->assertSame(0, JournalHeader::where('source_type', 'salary_payment')->count());
    }
}
