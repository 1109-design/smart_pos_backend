<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\InvoicePaymentPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoicePaymentPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoicePaymentPostingService $posting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->posting = app(InvoicePaymentPostingService::class);
    }

    private function makeLiveBusiness(string $id = 'biz-1', ?string $goLive = '2026-01-01', ?string $clientCutover = null, string $currencyCode = 'USD'): string
    {
        Tenant::create(['id' => $id, 'business_name' => $id, 'owner_email' => "{$id}@example.com"]);
        Business::create([
            'id' => $id, 'name' => $id, 'currency_code' => $currencyCode,
            'accounting_go_live_date' => $goLive, 'client_gl_posting_enabled_at' => $clientCutover,
        ]);
        (new ChartOfAccountsSeeder)->seedForBusiness($id);

        return $id;
    }

    private function account(string $businessId, string $code): GlAccount
    {
        return GlAccount::where('business_id', $businessId)->where('code', $code)->firstOrFail();
    }

    private function makeInvoice(string $businessId, string $customerId, float $total = 100): Invoice
    {
        return Invoice::create([
            'id' => (string) Str::uuid(), 'business_id' => $businessId, 'customer_id' => $customerId,
            'invoice_number' => 'INV-1', 'status' => 'sent', 'issue_date' => now(),
            'subtotal' => $total, 'total' => $total, 'amount_paid' => 0,
            'created_by_user_id' => (string) Str::uuid(),
        ]);
    }

    private function makePayment(string $invoiceId, float $amount, string $method = 'Cash', string $currencyCode = 'USD', float $exchangeRate = 1, ?string $paidAt = null): InvoicePayment
    {
        $payment = InvoicePayment::create([
            'id' => (string) Str::uuid(), 'invoice_id' => $invoiceId, 'method' => $method,
            'amount' => $amount, 'currency_code' => $currencyCode, 'exchange_rate_used' => $exchangeRate,
            'base_equivalent' => $amount * $exchangeRate,
            'recorded_by_user_id' => (string) Str::uuid(),
            'paid_at' => $paidAt ?? now(),
        ]);

        return $payment->fresh();
    }

    public function test_a_cutover_business_defers_to_the_client_and_posts_nothing(): void
    {
        $businessId = $this->makeLiveBusiness(clientCutover: '2026-06-01 00:00:00');
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Jane']);
        $invoice = $this->makeInvoice($businessId, $customer->id, 100);
        $payment = $this->makePayment($invoice->id, 40, paidAt: '2026-06-10');

        $this->posting->postIfReady($payment);

        $this->assertSame(0, JournalHeader::where('source_type', 'invoice_payment')->count());
    }
}
