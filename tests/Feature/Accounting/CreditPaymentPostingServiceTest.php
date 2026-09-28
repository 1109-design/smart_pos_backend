<?php

namespace Tests\Feature\Accounting;

use App\Console\Commands\Accounting\PostPendingCreditPayments;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\CreditPaymentPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreditPaymentPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private CreditPaymentPostingService $posting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->posting = app(CreditPaymentPostingService::class);
    }

    private function makeLiveBusiness(string $id = 'biz-1', ?string $goLive = '2026-01-01', ?string $clientCutover = null): string
    {
        Tenant::create(['id' => $id, 'business_name' => $id, 'owner_email' => "{$id}@example.com"]);
        Business::create([
            'id' => $id, 'name' => $id, 'currency_code' => 'USD',
            'accounting_go_live_date' => $goLive, 'client_gl_posting_enabled_at' => $clientCutover,
        ]);
        (new ChartOfAccountsSeeder)->seedForBusiness($id);

        return $id;
    }

    private function account(string $businessId, string $code): GlAccount
    {
        return GlAccount::where('business_id', $businessId)->where('code', $code)->firstOrFail();
    }

    private function makeCustomer(string $businessId): Customer
    {
        return Customer::create([
            'id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Jane',
        ]);
    }

    private function makeRepayment(string $customerId, float $amount, string $method = 'Cash', ?string $createdAt = null): CreditTransaction
    {
        $tx = CreditTransaction::create([
            'id' => (string) Str::uuid(), 'customer_id' => $customerId,
            'amount' => -$amount, 'type' => 'repayment', 'method' => $method,
        ]);
        if ($createdAt) {
            $tx->created_at = $createdAt;
            $tx->save();
        }

        return $tx->fresh();
    }

    public function test_a_cash_repayment_posts_dr_cash_cr_receivable_with_the_customer_as_party(): void
    {
        $businessId = $this->makeLiveBusiness();
        $customer = $this->makeCustomer($businessId);
        $repayment = $this->makeRepayment($customer->id, 40);

        $this->posting->postIfReady($repayment);

        $journal = JournalHeader::where('business_id', $businessId)
            ->where('source_type', 'credit_payment')->where('source_id', $repayment->id)->first();
        $this->assertNotNull($journal);
        $this->assertSame('posted', $journal->status);

        $cash = $this->account($businessId, '1000');
        $receivable = $this->account($businessId, '1100');
        $this->assertSame(40.0, $cash->balance());
        $this->assertSame(-40.0, $receivable->balance());

        $arLine = $journal->lines()->where('gl_account_id', $receivable->id)->first();
        $this->assertSame('customer', $arLine->party_type);
        $this->assertSame($customer->id, $arLine->party_id);
    }

    public function test_a_mobile_money_repayment_posts_against_the_mobile_clearing_account(): void
    {
        $businessId = $this->makeLiveBusiness();
        $customer = $this->makeCustomer($businessId);
        $repayment = $this->makeRepayment($customer->id, 25, 'EcoCash');

        $this->posting->postIfReady($repayment);

        $mobile = $this->account($businessId, '1020');
        $this->assertSame(25.0, $mobile->balance());
    }

    public function test_posting_twice_for_the_same_repayment_does_not_double_post(): void
    {
        $businessId = $this->makeLiveBusiness();
        $customer = $this->makeCustomer($businessId);
        $repayment = $this->makeRepayment($customer->id, 40);

        $this->posting->postIfReady($repayment);
        $this->posting->postIfReady($repayment);

        $count = JournalHeader::where('business_id', $businessId)
            ->where('source_type', 'credit_payment')->where('source_id', $repayment->id)->count();
        $this->assertSame(1, $count);
    }

    public function test_a_non_repayment_credit_transaction_is_ignored(): void
    {
        $businessId = $this->makeLiveBusiness();
        $customer = $this->makeCustomer($businessId);
        $purchase = CreditTransaction::create([
            'id' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'amount' => 40, 'type' => 'purchase',
        ]);

        $this->posting->postIfReady($purchase);

        $this->assertSame(0, JournalHeader::where('source_type', 'credit_payment')->count());
    }

    public function test_a_cutover_business_defers_to_the_client_and_posts_nothing(): void
    {
        $businessId = $this->makeLiveBusiness(clientCutover: '2026-06-01 00:00:00');
        $customer = $this->makeCustomer($businessId);
        $repayment = $this->makeRepayment($customer->id, 40, createdAt: '2026-06-10');

        $this->posting->postIfReady($repayment);

        $this->assertSame(0, JournalHeader::where('source_type', 'credit_payment')->count());
    }

    public function test_the_sweep_backfills_historical_unposted_repayments(): void
    {
        $businessId = $this->makeLiveBusiness(goLive: '2026-01-01');
        $customer = $this->makeCustomer($businessId);
        // Simulate history that predates this feature shipping — never posted.
        $old = $this->makeRepayment($customer->id, 40, createdAt: '2026-03-01');

        $this->artisan(PostPendingCreditPayments::class)->assertSuccessful();

        $journal = JournalHeader::where('source_type', 'credit_payment')->where('source_id', $old->id)->first();
        $this->assertNotNull($journal, 'the sweep must backfill pre-existing unposted repayment history');
        $this->assertSame('posted', $journal->status);
    }

    public function test_the_sweep_is_idempotent_on_rerun(): void
    {
        $businessId = $this->makeLiveBusiness();
        $customer = $this->makeCustomer($businessId);
        $this->makeRepayment($customer->id, 40, createdAt: '2026-03-01');

        $this->artisan(PostPendingCreditPayments::class)->assertSuccessful();
        $this->artisan(PostPendingCreditPayments::class)->assertSuccessful();

        $this->assertSame(1, JournalHeader::where('source_type', 'credit_payment')->count());
    }

    public function test_the_sweep_defers_a_cutover_business_within_the_grace_period_and_posts_after_it_elapses(): void
    {
        $businessId = $this->makeLiveBusiness(clientCutover: '2026-01-01 00:00:00');
        $customer = $this->makeCustomer($businessId);
        $repayment = $this->makeRepayment($customer->id, 40, createdAt: now()->subMinutes(30)->toDateTimeString());

        $this->artisan(PostPendingCreditPayments::class)->assertSuccessful();
        $this->assertSame(0, JournalHeader::where('source_type', 'credit_payment')->count());

        $repayment->created_at = now()->subHours(2);
        $repayment->save();

        $this->artisan(PostPendingCreditPayments::class)->assertSuccessful();
        $this->assertSame(1, JournalHeader::where('source_type', 'credit_payment')->count());
    }
}
