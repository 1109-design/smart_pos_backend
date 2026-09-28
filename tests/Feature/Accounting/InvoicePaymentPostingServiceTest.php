<?php

namespace Tests\Feature\Accounting;

use App\Console\Commands\Accounting\PostPendingInvoicePayments;
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

    public function test_a_cash_payment_posts_dr_cash_cr_receivable_with_the_customer_as_party(): void
    {
        $businessId = $this->makeLiveBusiness();
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Jane']);
        $invoice = $this->makeInvoice($businessId, $customer->id, 100);
        $payment = $this->makePayment($invoice->id, 40);

        $this->posting->postIfReady($payment);

        $journal = JournalHeader::where('business_id', $businessId)
            ->where('source_type', 'invoice_payment')->where('source_id', $payment->id)->first();
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

    public function test_a_foreign_currency_payment_records_the_original_amount_and_rate_on_the_tender_line(): void
    {
        $businessId = $this->makeLiveBusiness(currencyCode: 'USD');
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Jane']);
        $invoice = $this->makeInvoice($businessId, $customer->id, 100);
        // ZAR 740 at 0.054 -> USD 39.96 base equivalent
        $payment = $this->makePayment($invoice->id, 740, 'Cash', 'ZAR', 0.054);

        $this->posting->postIfReady($payment);

        $journal = JournalHeader::where('source_type', 'invoice_payment')->where('source_id', $payment->id)->first();
        $cashLine = $journal->lines()->where('gl_account_id', $this->account($businessId, '1000')->id)->first();

        $this->assertSame(39.96, round((float) $cashLine->debit, 2));
        $this->assertSame('ZAR', $cashLine->currency_code);
        $this->assertSame(0.054, round((float) $cashLine->exchange_rate, 3));
        $this->assertSame(740.0, (float) $cashLine->foreign_debit);

        $arLine = $journal->lines()->where('gl_account_id', $this->account($businessId, '1100')->id)->first();
        $this->assertSame('USD', $arLine->currency_code, 'the receivable control account line stays base-currency-only');
        $this->assertSame(0.0, (float) $arLine->foreign_credit);
    }

    public function test_posting_twice_for_the_same_payment_does_not_double_post(): void
    {
        $businessId = $this->makeLiveBusiness();
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Jane']);
        $invoice = $this->makeInvoice($businessId, $customer->id, 100);
        $payment = $this->makePayment($invoice->id, 40);

        $this->posting->postIfReady($payment);
        $this->posting->postIfReady($payment);

        $count = JournalHeader::where('source_type', 'invoice_payment')->where('source_id', $payment->id)->count();
        $this->assertSame(1, $count);
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

    public function test_the_sweep_backfills_historical_unposted_invoice_payments(): void
    {
        $businessId = $this->makeLiveBusiness(goLive: '2026-01-01');
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Jane']);
        $invoice = $this->makeInvoice($businessId, $customer->id, 100);
        $old = $this->makePayment($invoice->id, 40, paidAt: '2026-03-01');

        $this->artisan(PostPendingInvoicePayments::class)->assertSuccessful();

        $journal = JournalHeader::where('source_type', 'invoice_payment')->where('source_id', $old->id)->first();
        $this->assertNotNull($journal, 'the sweep must backfill pre-existing unposted invoice payment history');
        $this->assertSame('posted', $journal->status);
    }

    public function test_the_sweep_is_idempotent_on_rerun(): void
    {
        $businessId = $this->makeLiveBusiness();
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Jane']);
        $invoice = $this->makeInvoice($businessId, $customer->id, 100);
        $this->makePayment($invoice->id, 40, paidAt: '2026-03-01');

        $this->artisan(PostPendingInvoicePayments::class)->assertSuccessful();
        $this->artisan(PostPendingInvoicePayments::class)->assertSuccessful();

        $this->assertSame(1, JournalHeader::where('source_type', 'invoice_payment')->count());
    }
}
