<?php

namespace Tests\Feature\Accounting;

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
}
