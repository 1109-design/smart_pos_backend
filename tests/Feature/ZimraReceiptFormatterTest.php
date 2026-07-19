<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Zimra\ZimraDevice;
use App\Services\Zimra\ZimraReceiptFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZimraReceiptFormatterTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(): ZimraDevice
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
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

    public function test_formats_and_signs_an_inclusive_receipt(): void
    {
        $device = $this->makeDevice();
        $business = Business::create(['id' => 'biz-1', 'name' => 'Test Shop']);

        $transaction = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'user_id' => (string) Str::uuid(),
            'subtotal' => 100,
            'tax_total' => 15,
            'total' => 115,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => '202607-A1B2-9',
        ]);

        // 2 × 57.50 inclusive, 15% VAT inside: line 115.00 total, 15.00 tax.
        $item = new TransactionItem([
            'transaction_id' => $transaction->id,
            'product_name' => 'Widget',
            'quantity' => 2,
            'unit_price' => 57.50,
            'tax_amount' => 15.00,
            'line_total' => 115.00,
        ]);

        $payment = new Payment([
            'transaction_id' => $transaction->id,
            'method' => 'cash',
            'amount' => 115.00,
        ]);

        $result = ZimraReceiptFormatter::formatReceipt(
            $transaction,
            [$item],
            [$payment],
            $business,
            null,
            $device,
            null,
            42,
            7,
            $device->tax_codes,
            $device->applicable_taxes,
            '202607-A1B2-9'
        );

        $receipt = $result['receipt'];

        $this->assertSame('FiscalInvoice', $receipt['receiptType']);
        $this->assertSame('USD', $receipt['receiptCurrency']);
        $this->assertSame(42, $receipt['receiptGlobalNo']);
        $this->assertSame(7, $receipt['receiptCounter']);
        $this->assertTrue($receipt['receiptLinesTaxInclusive']);
        $this->assertSame(115.0, $receipt['receiptTotal']);

        $this->assertCount(1, $receipt['receiptLines']);
        $line = $receipt['receiptLines'][0];
        $this->assertSame(115.0, $line['receiptLineTotal']);
        $this->assertSame(57.5, $line['receiptLinePrice']);
        // 15/100 net = 15% → resolves to device taxID 3.
        $this->assertSame(3, $line['taxID']);
        $this->assertSame(15.0, $line['taxPercent']);
        $this->assertSame('A', $line['taxCode']);

        $this->assertCount(1, $receipt['receiptTaxes']);
        $tax = $receipt['receiptTaxes'][0];
        $this->assertSame(3, $tax['taxID']);
        $this->assertSame(15.0, $tax['taxAmount']);
        $this->assertSame(115.0, $tax['salesAmountWithTax']);

        $this->assertSame([['moneyTypeCode' => 'Cash', 'paymentAmount' => 115.0]], $receipt['receiptPayments']);

        // Signature must be present and verifiable with the device key.
        $this->assertNotEmpty($receipt['receiptDeviceSignature']['hash']);
        $this->assertNotEmpty($receipt['receiptDeviceSignature']['signature']);

        $publicKey = openssl_pkey_get_details(
            openssl_pkey_get_private($device->private_key_data)
        )['key'];
        // Rebuild the expected signing string from the receipt itself.
        $stringToSign = $device->device_id
            .'FISCALINVOICE'.'USD'.'42'.$receipt['receiptDate'].'11500'
            .'A'.'15.00'.'1500'.'11500';
        $this->assertSame(
            1,
            openssl_verify(
                $stringToSign,
                base64_decode($receipt['receiptDeviceSignature']['signature']),
                $publicKey,
                OPENSSL_ALGO_SHA256
            )
        );
    }

    public function test_zero_rated_items_resolve_to_zero_rated_tax_id(): void
    {
        $resolved = ZimraReceiptFormatter::resolveTax(0.0, [
            ['taxID' => 3, 'taxPercent' => 15.0, 'taxName' => 'VAT'],
            ['taxID' => 2, 'taxPercent' => 0.0, 'taxName' => 'Zero'],
            ['taxID' => 1, 'taxPercent' => null, 'taxName' => 'Exempt'],
        ]);

        $this->assertSame(2, $resolved['taxID']);
        $this->assertSame(0.0, $resolved['taxPercent']);
    }

    public function test_mixed_tax_receipt_groups_and_sorts_taxes_by_tax_id(): void
    {
        $device = $this->makeDevice();
        $business = Business::create(['id' => 'biz-1', 'name' => 'Test Shop']);

        $transaction = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'user_id' => (string) Str::uuid(),
            'subtotal' => 150,
            'tax_total' => 15,
            'total' => 165,
            'base_currency' => 'USD',
            'status' => 'completed',
        ]);

        $vatItem = new TransactionItem([
            'product_name' => 'Taxed',
            'quantity' => 1,
            'unit_price' => 115,
            'tax_amount' => 15.00,
            'line_total' => 115.00,
        ]);
        $zeroItem = new TransactionItem([
            'product_name' => 'Bread',
            'quantity' => 1,
            'unit_price' => 50,
            'tax_amount' => 0,
            'line_total' => 50.00,
        ]);

        $result = ZimraReceiptFormatter::formatReceipt(
            $transaction,
            [$vatItem, $zeroItem],
            [],
            $business,
            null,
            $device,
            'previous-hash-abc',
            43,
            8,
            $device->tax_codes,
            $device->applicable_taxes,
            'DOC-43'
        );

        $receipt = $result['receipt'];
        $this->assertSame(165.0, $receipt['receiptTotal']);
        $this->assertCount(2, $receipt['receiptTaxes']);
        // Sorted ascending by taxID: zero-rated (2) before VAT (3).
        $this->assertSame(2, $receipt['receiptTaxes'][0]['taxID']);
        $this->assertSame(3, $receipt['receiptTaxes'][1]['taxID']);
        $this->assertSame(0.0, $receipt['receiptTaxes'][0]['taxAmount']);
        $this->assertSame(15.0, $receipt['receiptTaxes'][1]['taxAmount']);
    }
}
