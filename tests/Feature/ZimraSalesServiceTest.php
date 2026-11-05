<?php

namespace Tests\Feature;

use App\Jobs\ProcessZimraFiscalisationJob;
use App\Models\Business;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\SalesReturn;
use App\Models\SyncRecord;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraSale;
use App\Services\Zimra\ZimraCloseDayPayloadBuilder;
use App\Services\Zimra\ZimraSalesService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZimraSalesServiceTest extends TestCase
{
    use RefreshDatabase;

    private ZimraDevice $device;

    private int $lastGlobalNo = 46;

    /** @var list<list<string>> Red validation codes for each receipt, in the order they are submitted. */
    private array $redCodesByReceipt = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'zimra.fiscalisation.enabled' => true,
            'zimra.fiscalisation.allowed_environments' => ['testing'],
            'zimra.fiscalisation.allowed_hosts' => [],
        ]);

        Business::create([
            'id' => 'biz-1',
            'name' => 'Fiscal Shop',
            'fiscalisation_enabled' => true,
            'tin' => '1234567890',
        ]);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKeyPem);

        $this->device = ZimraDevice::create([
            'business_id' => 'biz-1',
            'tin' => '1234567890',
            'device_id' => '321',
            'is_active' => true,
            'status' => 'active',
            'private_key_data' => $privateKeyPem,
            'fiscal_day_opened_at' => Carbon::now()->subHour(),
            'applicable_taxes' => [['taxID' => 3, 'taxPercent' => 15.0, 'taxName' => 'VAT 15%']],
            'tax_codes' => [3 => 'A'],
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'GetStatus')) {
                return Http::response([
                    'fiscalDayStatus' => 'FiscalDayOpened',
                    'lastFiscalDayNo' => 7,
                    'lastReceiptGlobalNo' => $this->lastGlobalNo,
                ]);
            }

            $this->lastGlobalNo++;
            $redCodes = array_shift($this->redCodesByReceipt) ?? [];

            return Http::response([
                'receiptID' => 11939000 + $this->lastGlobalNo,
                'serverDate' => now()->toIso8601String(),
                'receiptServerSignature' => ['signature' => 'server-signature'],
                'validationErrors' => array_map(
                    fn (string $code) => ['validationErrorCode' => $code, 'validationErrorColor' => 'Red'],
                    $redCodes
                ),
            ]);
        });
    }

    private function makeSale(string $saleNumber = '20261105-6036-3'): Transaction
    {
        $transaction = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'user_id' => (string) Str::uuid(),
            'client_name' => 'Walk-in Joe',
            'subtotal' => 115,
            'tax_total' => 15,
            'total' => 115,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => $saleNumber,
            'fiscalisation_requested' => true,
        ]);
        TransactionItem::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $transaction->id,
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Widget',
            'quantity' => 2,
            'unit_price' => 57.50,
            'tax_amount' => 15.00,
            'line_total' => 115.00,
        ]);
        Payment::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $transaction->id,
            'method' => 'cash',
            'amount' => 115.00,
            'currency_code' => 'USD',
            'base_equivalent' => 115.00,
        ]);

        return $transaction;
    }

    private function service(): ZimraSalesService
    {
        return app(ZimraSalesService::class);
    }

    private function submissions(): int
    {
        return count(Http::recorded(fn (Request $request) => str_contains($request->url(), 'SubmitReceipt')));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastFiscalSyncPayload(Transaction $transaction): ?array
    {
        return SyncRecord::where('table_name', 'transactions')
            ->where('record_uuid', $transaction->id)
            ->latest('id')
            ->first()?->payload;
    }

    public function test_a_sender_that_waited_for_the_lock_does_not_submit_the_sale_again(): void
    {
        $transaction = $this->makeSale();

        // The other sender finishes while this one is between its first check
        // and the device lock — the window behind receipts 49 and 50 on 5 Nov.
        $raced = false;
        ZimraSale::saved(function () use ($transaction, &$raced) {
            if (! $raced) {
                $raced = true;
                $this->service()->processQueued(Transaction::findOrFail($transaction->id));
            }
        });

        $result = $this->service()->processQueued($transaction);

        $this->assertTrue($raced);
        $this->assertSame(1, $this->submissions());
        $this->assertTrue($result['fiscalised']);
        $this->assertSame(47, ZimraSale::firstOrFail()->receipt_global_no);
        $this->assertSame('fiscalised', ZimraSale::firstOrFail()->status);
        $this->assertSame('fiscalised', $transaction->fresh()->fiscal_status);
    }

    public function test_an_accepted_sale_is_fiscalised_and_told_to_the_tills(): void
    {
        $transaction = $this->makeSale();

        $result = $this->service()->processQueued($transaction);

        $this->assertTrue($result['fiscalised']);
        $this->assertSame('fiscalised', $transaction->fresh()->fiscal_status);
        $this->assertSame('fiscalised', $this->lastFiscalSyncPayload($transaction)['fiscal_status']);
    }

    public function test_a_rejected_receipt_is_not_recorded_as_fiscalised(): void
    {
        $this->redCodesByReceipt = [['RCPT012', 'RCPT020']];
        $transaction = $this->makeSale();

        $result = $this->service()->processQueued($transaction);

        $this->assertFalse($result['fiscalised']);
        $zimraSale = ZimraSale::firstOrFail();
        $this->assertSame('rejected', $zimraSale->status);
        $this->assertSame('ZIMRA red validation errors: RCPT012, RCPT020', $zimraSale->error_message);
        $this->assertSame('failed', $transaction->fresh()->fiscal_status);
        $this->assertNull($transaction->fresh()->fiscal_receipt_number);
    }

    public function test_the_tills_are_told_when_zimra_rejects_a_sale(): void
    {
        $this->redCodesByReceipt = [['RCPT020']];
        $transaction = $this->makeSale();

        $this->service()->processQueued($transaction);

        $payload = $this->lastFiscalSyncPayload($transaction);
        $this->assertSame('failed', $payload['fiscal_status']);
        // A till upserts the whole row from this, so it must not blank these.
        $this->assertSame('Walk-in Joe', $payload['client_name']);
        $this->assertTrue($payload['fiscalisation_requested']);
    }

    public function test_the_tills_are_told_when_no_device_is_configured(): void
    {
        $this->device->update(['is_active' => false]);
        $transaction = $this->makeSale();

        $this->service()->queueFiscalisation($transaction);

        $this->assertSame('not_configured', $this->lastFiscalSyncPayload($transaction)['fiscal_status']);
    }

    public function test_a_rejected_sale_is_never_sent_again(): void
    {
        $this->redCodesByReceipt = [['RCPT013']];
        $transaction = $this->makeSale();
        $this->service()->processQueued($transaction);

        $result = $this->service()->processQueued($transaction->fresh());
        $retried = $this->service()->retryFailedFiscalisations();

        $this->assertSame(1, $this->submissions());
        $this->assertFalse($result['fiscalised']);
        $this->assertSame(0, $retried['attempted']);
        $this->assertSame('failed', $transaction->fresh()->fiscal_status);
    }

    public function test_a_rejection_saved_as_fiscalised_before_this_fix_is_recognised(): void
    {
        $transaction = $this->makeSale('20261105-6036-4');
        $transaction->update(['fiscal_status' => 'failed']);
        ZimraSale::create([
            'transaction_id' => $transaction->id,
            'business_id' => 'biz-1',
            'device_id' => '321',
            'status' => 'fiscalised',
            'zimra_receipt_id' => '11939675',
            'receipt_global_no' => 52,
            'fiscalised_at' => now(),
            'zimra_response' => ['validationErrors' => [
                ['validationErrorCode' => 'RCPT013', 'validationErrorColor' => 'Red'],
            ]],
        ]);

        $result = $this->service()->processQueued($transaction->fresh());

        $this->assertSame(0, $this->submissions());
        $this->assertFalse($result['fiscalised']);
        $this->assertSame('failed', $transaction->fresh()->fiscal_status);
    }

    public function test_a_warning_that_is_not_red_still_counts_as_fiscalised(): void
    {
        $transaction = $this->makeSale();
        ZimraSale::create([
            'transaction_id' => $transaction->id,
            'business_id' => 'biz-1',
            'device_id' => '321',
            'status' => 'pending',
            'zimra_receipt_id' => '11939000',
            'zimra_response' => ['validationErrors' => [
                ['validationErrorCode' => 'RCPT025', 'validationErrorColor' => 'Yellow'],
            ]],
        ]);

        $result = $this->service()->processQueued($transaction);

        $this->assertSame(0, $this->submissions());
        $this->assertTrue($result['fiscalised']);
        $this->assertSame('fiscalised', $transaction->fresh()->fiscal_status);
    }

    public function test_nothing_more_is_sent_after_a_rejected_receipt_in_the_same_day(): void
    {
        $this->redCodesByReceipt = [['RCPT020']];
        $this->service()->processQueued($this->makeSale('sale-1'));
        $next = $this->makeSale('sale-2');

        $result = $this->service()->processQueued($next);

        $this->assertSame(1, $this->submissions());
        $this->assertTrue($result['held']);
        $this->assertSame('pending', $next->fresh()->fiscal_status);
        $this->assertStringContainsString('Public Portal', $next->fresh()->fiscal_error);
    }

    public function test_the_day_close_still_counts_a_rejected_receipt(): void
    {
        $this->redCodesByReceipt = [['RCPT020']];
        $this->service()->processQueued($this->makeSale());

        $payload = app(ZimraCloseDayPayloadBuilder::class)->build($this->device->fresh(), 7, 1);

        $this->assertStringContainsString('115', json_encode($payload));
    }

    public function test_the_queue_stops_at_a_rejected_receipt(): void
    {
        $this->redCodesByReceipt = [['RCPT020']];
        $first = $this->makeSale('sale-1');
        $second = $this->makeSale('sale-2');
        foreach ([$first, $second] as $index => $sale) {
            ZimraSale::create([
                'transaction_id' => $sale->id,
                'business_id' => 'biz-1',
                'device_id' => '321',
                'status' => 'pending',
                'created_at' => now()->addSeconds($index),
            ]);
        }

        (new ProcessZimraFiscalisationJob('321'))->handle($this->service());

        $this->assertSame('failed', $first->fresh()->fiscal_status);
        $this->assertSame(1, $this->submissions());
        $this->assertNotSame('fiscalised', $second->fresh()->fiscal_status);
    }

    public function test_the_tills_get_zimras_codes_for_a_rejected_sale(): void
    {
        $this->redCodesByReceipt = [['RCPT012', 'RCPT020']];
        $transaction = $this->makeSale();

        $this->service()->processQueued($transaction);

        $this->assertSame('RCPT012, RCPT020', $transaction->fresh()->fiscal_error);
        $this->assertSame('RCPT012, RCPT020', $this->lastFiscalSyncPayload($transaction)['fiscal_error']);
    }

    public function test_a_credit_sale_goes_to_zimra_as_a_credit_payment(): void
    {
        $sale = $this->makeSale();
        $sale->update(['status' => 'credit_sale']);
        Payment::where('transaction_id', $sale->id)->update(['method' => 'credit']);

        $result = $this->service()->processQueued($sale->fresh());

        $this->assertTrue($result['fiscalised']);
        $receipt = ZimraSale::firstOrFail()->submitted_receipt;
        $this->assertSame('FiscalInvoice', $receipt['receiptType']);
        $this->assertSame('Credit', $receipt['receiptPayments'][0]['moneyTypeCode']);
    }

    /**
     * A fiscalised sale, and a till's reversal row for part of it: positive
     * lines and payments on a negative total, as sales_return_service.dart writes.
     */
    private function makeRefund(?Transaction $original = null, float $amount = 57.50, float $tax = 7.50): Transaction
    {
        $original ??= tap($this->makeSale(), fn (Transaction $sale) => $this->service()->processQueued($sale));

        $reversal = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'user_id' => (string) Str::uuid(),
            'subtotal' => -($amount - $tax),
            'tax_total' => -$tax,
            'total' => -$amount,
            'base_currency' => 'USD',
            'status' => 'refunded',
            'sale_number' => 'REF-'.$original->sale_number,
            'fiscalisation_requested' => true,
        ]);
        TransactionItem::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $reversal->id,
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Widget',
            'quantity' => 1,
            'unit_price' => 57.50,
            'tax_amount' => $tax,
            'line_total' => $amount,
        ]);
        Payment::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $reversal->id,
            'method' => 'cash',
            'amount' => $amount,
            'currency_code' => 'USD',
            'base_equivalent' => $amount,
        ]);
        SalesReturn::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'return_number' => 'RET-1',
            'original_transaction_id' => $original->id,
            'return_transaction_id' => $reversal->id,
            'outcome' => 'refund',
            'returned_value' => $amount,
            'net_amount' => -$amount,
            'reason' => 'Damaged in box',
            'requested_by_user_id' => (string) Str::uuid(),
        ]);

        return $reversal;
    }

    public function test_a_refund_is_sent_as_a_credit_note_against_the_original_receipt(): void
    {
        $original = $this->makeSale();
        $this->service()->processQueued($original);
        $originalReceiptId = (int) ZimraSale::where('transaction_id', $original->id)->value('zimra_receipt_id');
        $reversal = $this->makeRefund($original);

        $signed = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$signed) {
            if ($log->message === 'ZIMRA signing string') {
                $signed[] = $log->context['string_to_sign'];
            }
        });

        $result = $this->service()->processQueued($reversal);

        $this->assertTrue($result['fiscalised']);
        $this->assertSame('fiscalised', $reversal->fresh()->fiscal_status);

        $zimraSale = ZimraSale::where('transaction_id', $reversal->id)->firstOrFail();
        $receipt = $zimraSale->submitted_receipt;

        $this->assertSame('CreditNote', $receipt['receiptType']);
        $this->assertSame(['receiptID' => $originalReceiptId], $receipt['creditDebitNote']);
        $this->assertSame('Damaged in box', $receipt['receiptNotes']);
        $this->assertEquals(-57.50, $receipt['receiptTotal']);
        $this->assertEquals(1, $receipt['receiptLines'][0]['receiptLineQuantity']);
        $this->assertEquals(-57.50, $receipt['receiptLines'][0]['receiptLinePrice']);
        $this->assertEquals(-57.50, $receipt['receiptLines'][0]['receiptLineTotal']);
        $this->assertEquals(15.0, $receipt['receiptLines'][0]['taxPercent']);
        $this->assertEquals(-7.50, $receipt['receiptTaxes'][0]['taxAmount']);
        $this->assertEquals(-57.50, $receipt['receiptTaxes'][0]['salesAmountWithTax']);
        $this->assertEquals(-57.50, $receipt['receiptPayments'][0]['paymentAmount']);

        // Spec §13.2.1: upper-case type, negative amounts in cents.
        $this->assertCount(1, $signed);
        $this->assertStringContainsString('321CREDITNOTEUSD', $signed[0]);
        $this->assertStringContainsString('-5750A15.00-750-5750', $signed[0]);
    }

    public function test_a_credit_note_is_never_sent_twice(): void
    {
        $reversal = $this->makeRefund();
        $this->service()->processQueued($reversal);
        $sent = $this->submissions();

        $this->service()->processQueued($reversal->fresh());

        $this->assertSame($sent, $this->submissions());
    }

    public function test_a_refund_of_a_sale_never_fiscalised_needs_no_credit_note(): void
    {
        $original = $this->makeSale();
        $reversal = $this->makeRefund($original);

        $this->service()->queueFiscalisation($reversal);

        $this->assertSame(0, $this->submissions());
        $this->assertSame('not_applicable', $reversal->fresh()->fiscal_status);
        $this->assertSame('not_applicable', $this->lastFiscalSyncPayload($reversal)['fiscal_status']);
    }

    public function test_a_refund_of_a_rejected_sale_is_flagged_and_not_sent(): void
    {
        $this->redCodesByReceipt = [['RCPT020']];
        $original = $this->makeSale();
        $this->service()->processQueued($original);
        $sent = $this->submissions();
        $reversal = $this->makeRefund($original);

        $this->service()->processQueued($reversal);

        $this->assertSame($sent, $this->submissions());
        $this->assertSame('failed', $reversal->fresh()->fiscal_status);
        $this->assertSame('The original sale was not accepted by ZIMRA', $reversal->fresh()->fiscal_error);
    }

    public function test_a_refund_waits_while_its_original_sale_is_still_queued(): void
    {
        $original = $this->makeSale();
        ZimraSale::create([
            'transaction_id' => $original->id,
            'business_id' => 'biz-1',
            'device_id' => '321',
            'status' => 'pending',
        ]);
        $reversal = $this->makeRefund($original);

        $this->service()->processQueued($reversal);

        $this->assertSame(0, $this->submissions());
        $this->assertSame('pending', ZimraSale::where('transaction_id', $reversal->id)->value('status'));

        $this->service()->processQueued($original);
        $this->service()->processQueued($reversal->fresh());

        $this->assertSame('fiscalised', $reversal->fresh()->fiscal_status);
    }

    public function test_a_refund_of_a_sale_over_12_months_old_is_not_sent(): void
    {
        $original = $this->makeSale();
        $this->service()->processQueued($original);
        ZimraSale::where('transaction_id', $original->id)->update(['fiscalised_at' => now()->subMonths(13)]);
        $sent = $this->submissions();
        $reversal = $this->makeRefund($original);

        $this->service()->processQueued($reversal);

        $this->assertSame($sent, $this->submissions());
        $this->assertStringContainsString('12 months', $reversal->fresh()->fiscal_error);
    }

    public function test_a_refund_with_no_items_yet_waits_instead_of_sending_an_empty_receipt(): void
    {
        $reversal = $this->makeRefund();
        TransactionItem::where('transaction_id', $reversal->id)->delete();
        $sent = $this->submissions();

        $this->service()->processQueued($reversal);

        $this->assertSame($sent, $this->submissions());
        $this->assertNotSame('failed', $reversal->fresh()->fiscal_status);
    }

    public function test_the_day_close_keeps_credit_notes_in_their_own_negative_counters(): void
    {
        $original = $this->makeSale();
        $this->service()->processQueued($original);
        $this->service()->processQueued($this->makeRefund($original));

        $payload = app(ZimraCloseDayPayloadBuilder::class)->build($this->device->fresh(), 7, 2);

        $counters = collect($payload['fiscalDayCounters']);
        $value = fn (string $type) => $counters->firstWhere('fiscalCounterType', $type)['fiscalCounterValue'] ?? null;

        $this->assertEquals(115, $value('SaleByTax'));
        $this->assertEquals(15, $value('SaleTaxByTax'));
        $this->assertEquals(-57.50, $value('CreditNoteByTax'));
        $this->assertEquals(-7.50, $value('CreditNoteTaxByTax'));
        $this->assertEquals(57.50, $value('BalanceByMoneyType'));
        $this->assertSame(
            ['SaleByTax', 'SaleTaxByTax', 'CreditNoteByTax', 'CreditNoteTaxByTax', 'BalanceByMoneyType'],
            $counters->pluck('fiscalCounterType')->all()
        );
    }

    public function test_a_day_where_refunds_outweigh_sales_still_reports_the_negative_balance(): void
    {
        $original = $this->makeSale();
        $this->service()->processQueued($original);
        ZimraSale::where('transaction_id', $original->id)->update(['fiscalised_at' => now()->subDay()]);
        $this->device->update(['fiscal_day_opened_at' => now()->subHour()]);
        $this->service()->processQueued($this->makeRefund($original));

        $payload = app(ZimraCloseDayPayloadBuilder::class)->build($this->device->fresh(), 8, 1);

        $balance = collect($payload['fiscalDayCounters'])->firstWhere('fiscalCounterType', 'BalanceByMoneyType');
        $this->assertEquals(-57.50, $balance['fiscalCounterValue']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeInvoice(array $attributes = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'customer_id' => (string) Str::uuid(),
            'invoice_number' => 'INV-'.Str::random(5),
            'status' => 'sent',
            'issue_date' => now()->toDateString(),
            'subtotal' => 100,
            'tax_total' => 15,
            'total' => 115,
            'created_by_user_id' => (string) Str::uuid(),
        ], $attributes));
        InvoiceItem::create([
            'id' => (string) Str::uuid(),
            'invoice_id' => $invoice->id,
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Consulting',
            'quantity' => 2,
            'unit_price' => 50,
            'tax_amount' => 15,
            'line_total' => 115,
        ]);

        return $invoice->fresh();
    }

    private function makeCreditNote(Invoice $invoice, float $total = 57.50, float $tax = 7.50, ?string $reason = 'Overbilled'): CreditNote
    {
        $note = CreditNote::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'credit_note_number' => 'CN-'.Str::random(5),
            'reason' => $reason,
            'subtotal' => $total - $tax,
            'tax_total' => $tax,
            'total' => $total,
            'created_by_user_id' => (string) Str::uuid(),
        ]);
        CreditNoteItem::create([
            'id' => (string) Str::uuid(),
            'credit_note_id' => $note->id,
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Consulting',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_amount' => $tax,
            'line_total' => $total,
        ]);

        return $note->fresh();
    }

    public function test_an_issued_invoice_goes_to_zimra_as_an_a4_invoice_on_account(): void
    {
        $invoice = $this->makeInvoice();

        $result = $this->service()->processQueued($invoice);

        $this->assertTrue($result['fiscalised']);
        $receipt = ZimraSale::where('invoice_id', $invoice->id)->firstOrFail()->submitted_receipt;
        $this->assertSame('FiscalInvoice', $receipt['receiptType']);
        $this->assertSame('InvoiceA4', $receipt['receiptPrintForm']);
        $this->assertSame($invoice->invoice_number, $receipt['invoiceNo']);
        $this->assertEquals(115, $receipt['receiptTotal']);
        $this->assertEquals(57.50, $receipt['receiptLines'][0]['receiptLinePrice']);
        $this->assertEquals(15.0, $receipt['receiptLines'][0]['taxPercent']);
        $this->assertEquals(15, $receipt['receiptTaxes'][0]['taxAmount']);
        $this->assertSame('Credit', $receipt['receiptPayments'][0]['moneyTypeCode']);
        $this->assertEquals(115, $receipt['receiptPayments'][0]['paymentAmount']);

        $fresh = $invoice->fresh();
        $this->assertSame('fiscalised', $fresh->fiscal_status);
        $this->assertNotEmpty($fresh->fiscal_qr_code);
    }

    public function test_the_tills_are_told_only_the_fiscal_result_of_an_invoice(): void
    {
        $invoice = $this->makeInvoice();

        $this->service()->processQueued($invoice);

        $record = SyncRecord::where('table_name', 'invoices')->where('record_uuid', $invoice->id)->latest('id')->firstOrFail();
        $this->assertTrue($record->payload['fiscal_only']);
        $this->assertSame('fiscalised', $record->payload['fiscal_status']);
        $this->assertEquals(115, $record->payload['total']);
        $this->assertSame($invoice->issue_date->toDateString(), $record->payload['issue_date']);
    }

    public function test_a_foreign_currency_invoice_is_declared_as_the_customer_sees_it(): void
    {
        $invoice = $this->makeInvoice(['currency_code' => 'ZWG', 'exchange_rate' => 2]);

        $this->service()->processQueued($invoice);

        $receipt = ZimraSale::where('invoice_id', $invoice->id)->firstOrFail()->submitted_receipt;
        $this->assertSame('ZWG', $receipt['receiptCurrency']);
        $this->assertEquals(230, $receipt['receiptTotal']);
        $this->assertEquals(30, $receipt['receiptTaxes'][0]['taxAmount']);
        $this->assertEquals(230, $receipt['receiptPayments'][0]['paymentAmount']);
    }

    public function test_an_invoice_with_lines_still_on_their_way_is_not_sent_short(): void
    {
        $invoice = $this->makeInvoice(['total' => 230, 'tax_total' => 30]);

        $result = $this->service()->processQueued($invoice);

        $this->assertSame(0, $this->submissions());
        $this->assertFalse($result['fiscalised']);
        $this->assertSame('pending', ZimraSale::where('invoice_id', $invoice->id)->value('status'));

        InvoiceItem::create([
            'id' => (string) Str::uuid(),
            'invoice_id' => $invoice->id,
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Second line',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_amount' => 15,
            'line_total' => 115,
        ]);

        $this->assertSame(0, $this->service()->retryFailedFiscalisations()['failed']);
        $this->assertSame('fiscalised', $invoice->fresh()->fiscal_status);
    }

    public function test_an_invoice_is_only_queued_on_the_move_to_issued(): void
    {
        $invoice = $this->makeInvoice();

        $this->service()->queueInvoiceIfIssued($invoice, 'sent');
        $this->assertSame(0, ZimraSale::count());

        $this->service()->queueInvoiceIfIssued($invoice, 'draft');
        $this->assertSame(1, ZimraSale::count());
    }

    public function test_paying_an_invoice_issued_before_go_live_sends_nothing(): void
    {
        $invoice = $this->makeInvoice(['status' => 'paid']);

        $this->service()->queueInvoiceIfIssued($invoice, 'partial');

        $this->assertSame(0, ZimraSale::count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function documentsThatMustNeverReachZimra(): array
    {
        return [
            'pro-forma' => [['type' => 'pro_forma']],
            'from till receipts, already declared' => [['type' => 'from_receipts', 'status' => 'paid']],
            'opening balance, pushed as issued' => [['status' => 'issued', 'notes' => 'Opening Balance: Old system']],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('documentsThatMustNeverReachZimra')]
    public function test_a_document_that_is_not_a_new_supply_never_reaches_zimra(array $attributes): void
    {
        $this->service()->queueInvoiceIfIssued($this->makeInvoice($attributes), null);

        $this->assertSame(0, ZimraSale::count());
    }

    public function test_a_draft_or_cancelled_invoice_is_not_sent(): void
    {
        $this->service()->queueInvoiceIfIssued($this->makeInvoice(['status' => 'draft']), null);
        $this->service()->queueInvoiceIfIssued($this->makeInvoice(['status' => 'cancelled']), 'draft');

        $this->assertSame(0, ZimraSale::count());
    }

    public function test_a_credit_note_references_its_invoices_receipt(): void
    {
        $invoice = $this->makeInvoice();
        $this->service()->processQueued($invoice);
        $invoiceReceiptId = (int) ZimraSale::where('invoice_id', $invoice->id)->value('zimra_receipt_id');
        $note = $this->makeCreditNote($invoice);

        $result = $this->service()->processQueued($note);

        $this->assertTrue($result['fiscalised']);
        $receipt = ZimraSale::where('credit_note_id', $note->id)->firstOrFail()->submitted_receipt;
        $this->assertSame('CreditNote', $receipt['receiptType']);
        $this->assertSame(['receiptID' => $invoiceReceiptId], $receipt['creditDebitNote']);
        $this->assertSame('Overbilled', $receipt['receiptNotes']);
        $this->assertEquals(-57.50, $receipt['receiptTotal']);
        $this->assertEquals(-7.50, $receipt['receiptTaxes'][0]['taxAmount']);
        $this->assertSame('Credit', $receipt['receiptPayments'][0]['moneyTypeCode']);
        $this->assertEquals(-57.50, $receipt['receiptPayments'][0]['paymentAmount']);
        $this->assertSame('fiscalised', $note->fresh()->fiscal_status);
    }

    public function test_a_credit_note_with_no_reason_still_carries_the_note_zimra_requires(): void
    {
        $invoice = $this->makeInvoice(['invoice_number' => 'INV-77']);
        $this->service()->processQueued($invoice);
        $note = $this->makeCreditNote($invoice, reason: null);

        $this->service()->processQueued($note);

        $receipt = ZimraSale::where('credit_note_id', $note->id)->firstOrFail()->submitted_receipt;
        $this->assertSame('Credit note against invoice INV-77', $receipt['receiptNotes']);
    }

    public function test_a_credit_note_uses_its_invoices_currency_and_rate(): void
    {
        $invoice = $this->makeInvoice(['currency_code' => 'ZWG', 'exchange_rate' => 2]);
        $this->service()->processQueued($invoice);
        $note = $this->makeCreditNote($invoice);

        $this->service()->processQueued($note);

        $receipt = ZimraSale::where('credit_note_id', $note->id)->firstOrFail()->submitted_receipt;
        $this->assertSame('ZWG', $receipt['receiptCurrency']);
        $this->assertEquals(-115, $receipt['receiptTotal']);
    }

    public function test_a_credit_note_on_an_invoice_zimra_never_had_needs_no_receipt(): void
    {
        $note = $this->makeCreditNote($this->makeInvoice(['notes' => 'Opening Balance: Old']));

        $this->service()->queueFiscalisation($note);

        $this->assertSame(0, $this->submissions());
        $this->assertSame('not_applicable', $note->fresh()->fiscal_status);
    }

    public function test_a_credit_note_on_a_rejected_invoice_is_flagged_and_not_sent(): void
    {
        $this->redCodesByReceipt = [['RCPT020']];
        $invoice = $this->makeInvoice();
        $this->service()->processQueued($invoice);
        $sent = $this->submissions();
        $note = $this->makeCreditNote($invoice);

        $this->service()->processQueued($note);

        $this->assertSame($sent, $this->submissions());
        $this->assertSame('failed', $note->fresh()->fiscal_status);
        $this->assertSame('RCPT020', $invoice->fresh()->fiscal_error);
    }

    public function test_the_queue_drains_sales_invoices_and_credit_notes_in_order(): void
    {
        $sale = $this->makeSale('sale-1');
        $invoice = $this->makeInvoice();
        $this->service()->queueFiscalisation($sale);
        $this->service()->queueFiscalisation($invoice);

        (new ProcessZimraFiscalisationJob('321'))->handle($this->service());

        $this->assertSame('fiscalised', $sale->fresh()->fiscal_status);
        $this->assertSame('fiscalised', $invoice->fresh()->fiscal_status);
        $this->assertSame([47, 48], ZimraSale::orderBy('receipt_global_no')->pluck('receipt_global_no')->all());
    }

    public function test_the_day_close_adds_invoices_and_their_credit_notes_to_the_counters(): void
    {
        $invoice = $this->makeInvoice();
        $this->service()->processQueued($invoice);
        $this->service()->processQueued($this->makeCreditNote($invoice));

        $counters = collect(app(ZimraCloseDayPayloadBuilder::class)->build($this->device->fresh(), 7, 2)['fiscalDayCounters']);

        $this->assertEquals(115, $counters->firstWhere('fiscalCounterType', 'SaleByTax')['fiscalCounterValue']);
        $this->assertEquals(-57.50, $counters->firstWhere('fiscalCounterType', 'CreditNoteByTax')['fiscalCounterValue']);
        $balance = $counters->firstWhere('fiscalCounterType', 'BalanceByMoneyType');
        $this->assertSame('Credit', $balance['fiscalCounterMoneyType']);
        $this->assertEquals(57.50, $balance['fiscalCounterValue']);
    }

    public function test_a_buyer_with_a_valid_tin_is_named_on_the_receipt(): void
    {
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => 'biz-1', 'name' => 'Acme Ltd', 'tax_number' => '2000123456', 'vat_number' => '220012345']);
        $invoice = $this->makeInvoice(['customer_id' => $customer->id]);

        $this->service()->processQueued($invoice);

        $receipt = ZimraSale::where('invoice_id', $invoice->id)->firstOrFail()->submitted_receipt;
        $this->assertSame(
            ['buyerRegisterName' => 'Acme Ltd', 'buyerTIN' => '2000123456', 'VATNumber' => '220012345'],
            $receipt['buyerData']
        );
    }

    public function test_a_customer_with_a_bad_or_missing_tin_is_left_off_the_receipt(): void
    {
        foreach (['12345', null] as $tin) {
            $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => 'biz-1', 'name' => 'Acme Ltd', 'tax_number' => $tin]);
            $invoice = $this->makeInvoice(['customer_id' => $customer->id]);

            $this->service()->processQueued($invoice);

            $receipt = ZimraSale::where('invoice_id', $invoice->id)->firstOrFail()->submitted_receipt;
            $this->assertArrayNotHasKey('buyerData', $receipt);
        }
    }
}
