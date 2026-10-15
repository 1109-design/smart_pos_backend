<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Transaction;
use App\Models\Zimra\FiscalDocument;
use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraSale;
use App\Services\Zimra\ZimraClient;
use App\Services\Zimra\ZimraCloseDayPayloadBuilder;
use App\Services\Zimra\ZimraReceiptFormatter;
use App\Services\Zimra\ZimraSalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Phase 11.2 — customer invoices and credit / debit notes through the
 * ZIMRA chain, per the FDMS v7.2 rules: a credit note's line prices,
 * taxes, payments and total are negative (RCPT022 / RCPT028 / RCPT040),
 * it names the receipt it credits (RCPT015) and carries a reason
 * (RCPT034); close-day counters keep credit notes apart.
 */
class ZimraFiscalDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(): ZimraDevice
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKeyPem);

        return ZimraDevice::create([
            'business_id' => 'biz-1',
            'tin' => '1234567890',
            'device_id' => '321',
            'is_active' => true,
            'status' => 'active',
            'private_key_data' => $privateKeyPem,
            'applicable_taxes' => [
                ['taxID' => 3, 'taxPercent' => 15.0, 'taxName' => 'VAT 15%'],
                ['taxID' => 2, 'taxPercent' => 0.0, 'taxName' => 'Zero rated'],
            ],
            'tax_codes' => [3 => 'A', 2 => 'B'],
        ]);
    }

    public function test_a_credit_note_is_negative_names_its_original_and_signs_as_such(): void
    {
        $device = $this->makeDevice();
        $business = Business::create(['id' => 'biz-1', 'name' => 'Test Shop']);
        $shape = new Transaction(['business_id' => 'biz-1', 'base_currency' => 'USD']);
        $item = (object) ['product_name' => 'Widget', 'quantity' => 2, 'line_total' => -115.0, 'tax_amount' => -15.0];
        $payment = (object) ['method' => 'cash', 'amount' => 115.0, 'base_equivalent' => null];

        $receipt = ZimraReceiptFormatter::formatReceipt(
            $shape, [$item], [$payment], $business, null, $device, null, 43, 8,
            $device->tax_codes, $device->applicable_taxes, 'CN-001',
            ['receiptType' => 'CreditNote', 'creditDebitNote' => ['receiptID' => 9001], 'receiptNotes' => 'Returned']
        )['receipt'];

        $this->assertSame('CreditNote', $receipt['receiptType']);
        $this->assertSame(['receiptID' => 9001], $receipt['creditDebitNote']);
        $this->assertSame('Returned', $receipt['receiptNotes']);
        $this->assertSame(-115.0, $receipt['receiptTotal']);
        $line = $receipt['receiptLines'][0];
        $this->assertSame(-57.5, $line['receiptLinePrice']);
        $this->assertSame(2.0, $line['receiptLineQuantity']);
        $this->assertSame(3, $line['taxID']); // 15% read from the magnitudes
        $this->assertSame(-15.0, $receipt['receiptTaxes'][0]['taxAmount']);
        $this->assertSame(-115.0, $receipt['receiptTaxes'][0]['salesAmountWithTax']);
        $this->assertSame([['moneyTypeCode' => 'Cash', 'paymentAmount' => -115.0]], $receipt['receiptPayments']);

        $publicKey = openssl_pkey_get_details(openssl_pkey_get_private($device->private_key_data))['key'];
        $stringToSign = '321CREDITNOTEUSD43'.$receipt['receiptDate'].'-11500A15.00-1500-11500';
        $this->assertSame(1, openssl_verify(
            $stringToSign,
            base64_decode($receipt['receiptDeviceSignature']['signature']),
            $publicKey,
            OPENSSL_ALGO_SHA256
        ));
    }

    public function test_close_day_counters_keep_credit_notes_apart(): void
    {
        $device = $this->makeDevice();
        $device->update(['fiscal_day_opened_at' => now()->subHour()]);
        $receipts = [
            ['FiscalInvoice', 115.0, 15.0],
            ['CreditNote', -57.5, -7.5],
        ];
        foreach ($receipts as $n => [$type, $total, $tax]) {
            ZimraSale::create([
                'transaction_id' => (string) Str::uuid(),
                'business_id' => 'biz-1',
                'device_id' => '321',
                'status' => 'fiscalised',
                'fiscalised_at' => now(),
                'receipt_global_no' => $n + 1,
                'submitted_receipt' => [
                    'receiptType' => $type,
                    'receiptCurrency' => 'USD',
                    'receiptTaxes' => [['taxID' => 3, 'taxPercent' => 15.0, 'taxAmount' => $tax, 'salesAmountWithTax' => $total]],
                    'receiptPayments' => [['moneyTypeCode' => 'Cash', 'paymentAmount' => $total]],
                ],
            ]);
        }

        $counters = collect((new ZimraCloseDayPayloadBuilder)->build($device->fresh(), 5, 2)['fiscalDayCounters'])
            ->mapWithKeys(fn ($c) => [$c['fiscalCounterType'] => $c['fiscalCounterValue']]);

        $this->assertSame([
            'SaleByTax' => 115.0,
            'SaleTaxByTax' => 15.0,
            'CreditNoteByTax' => -57.5,
            'CreditNoteTaxByTax' => -7.5,
            'BalanceByMoneyType' => 57.5,
        ], $counters->all());
    }

    public function test_a_customer_invoice_then_its_credit_note_go_through_the_chain(): void
    {
        Queue::fake();
        config(['zimra.fiscalisation.allowed_environments' => ['testing'], 'zimra.fiscalisation.enabled' => true]);
        Business::create(['id' => 'biz-1', 'name' => 'Test Shop', 'fiscalisation_enabled' => true]);
        $this->makeDevice()->update(['fiscal_day_opened_at' => now()->subHour()]);

        $submitted = [];
        $globalNo = 10;
        $this->mock(ZimraClient::class, function (MockInterface $mock) use (&$submitted, &$globalNo) {
            $mock->shouldReceive('getStatus')->andReturnUsing(fn () => [
                'success' => true,
                'data' => ['fiscalDayStatus' => 'FiscalDayOpened', 'lastReceiptGlobalNo' => $globalNo],
            ]);
            $mock->shouldReceive('submitReceipt')->andReturnUsing(function ($deviceId, $receipt) use (&$submitted, &$globalNo) {
                $submitted[] = $receipt['receipt'];
                $globalNo++;

                return ['success' => true, 'data' => [
                    'receiptID' => 5000 + count($submitted),
                    'serverDate' => now()->toIso8601String(),
                    'receiptServerSignature' => ['hash' => 'h', 'signature' => 's'.count($submitted)],
                ]];
            });
        });
        $service = app(ZimraSalesService::class);

        $invoice = FiscalDocument::create([
            'business_id' => 'biz-1', 'document_type' => 'FiscalInvoice', 'source_type' => 'ar_invoice',
            'source_id' => 'inv-1', 'document_number' => 'INV-1', 'currency_code' => 'USD', 'total' => 115,
            'lines' => [['description' => 'Widget', 'quantity' => 2, 'line_total' => 115, 'tax_amount' => 15]],
            'payments' => [['method' => 'credit', 'amount' => 115]],
        ]);
        $this->assertTrue($service->processQueuedDocument($invoice)['fiscalised']);
        $this->assertSame('fiscalised', $invoice->fresh()->status);
        $this->assertSame('5001', $invoice->fresh()->fiscal_receipt_id);

        $note = FiscalDocument::create([
            'business_id' => 'biz-1', 'document_type' => 'CreditNote', 'source_type' => 'credit_note',
            'source_id' => 'cn-1', 'document_number' => 'CN-1', 'original_document_id' => $invoice->id,
            'currency_code' => 'USD', 'total' => 57.5, 'reason' => 'One returned',
            'lines' => [['description' => 'Widget', 'quantity' => 1, 'line_total' => 57.5, 'tax_amount' => 7.5]],
            'payments' => [['method' => 'credit', 'amount' => 57.5]],
        ]);
        $this->assertTrue($service->processQueuedDocument($note)['fiscalised']);
        $credit = $submitted[1];
        $this->assertSame('CreditNote', $credit['receiptType']);
        $this->assertSame(['receiptID' => 5001], $credit['creditDebitNote']);
        $this->assertSame(-57.5, $credit['receiptTotal']);
        $this->assertSame('One returned', $credit['receiptNotes']);
    }

    public function test_a_note_against_a_sale_that_was_never_fiscalised_is_not_required(): void
    {
        Queue::fake();
        config(['zimra.fiscalisation.allowed_environments' => ['testing']]);
        Business::create(['id' => 'biz-1', 'name' => 'Test Shop', 'fiscalisation_enabled' => true]);
        $this->makeDevice();
        $sale = Transaction::create([
            'id' => (string) Str::uuid(), 'business_id' => 'biz-1', 'user_id' => (string) Str::uuid(),
            'subtotal' => 10, 'total' => 10, 'base_currency' => 'USD', 'status' => 'completed',
            'fiscalisation_requested' => false,
        ]);
        $note = FiscalDocument::create([
            'business_id' => 'biz-1', 'document_type' => 'CreditNote', 'source_type' => 'sales_return',
            'source_id' => 'ret-1', 'document_number' => 'RET-1', 'original_transaction_id' => $sale->id,
            'currency_code' => 'USD', 'total' => 10, 'reason' => 'Returned', 'lines' => [], 'payments' => [],
        ]);

        app(ZimraSalesService::class)->processQueuedDocument($note);

        $this->assertSame('not_required', $note->fresh()->status);
    }
}
