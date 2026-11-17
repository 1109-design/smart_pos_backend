<?php

namespace Tests\Feature;

use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraSale;
use App\Services\Zimra\ZimraCloseDayPayloadBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZimraCloseDayPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    private ZimraDevice $device;

    private int $receiptGlobalNo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKeyPem);

        $this->device = ZimraDevice::create([
            'business_id' => 'biz-1',
            'tin' => '1234567890',
            'device_id' => '321',
            'is_active' => true,
            'status' => 'active',
            'private_key_data' => $privateKeyPem,
            'fiscal_day_opened_at' => Carbon::parse('2026-11-05 06:00:00'),
            'applicable_taxes' => [['taxID' => 517, 'taxPercent' => 15.5, 'taxName' => 'VAT 15.5%']],
            'tax_codes' => [517 => 'A'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $taxes
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function makeAcceptedReceipt(array $taxes, array $payments, string $currency = 'USD'): void
    {
        ZimraSale::create([
            'transaction_id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'device_id' => '321',
            'status' => 'fiscalised',
            'receipt_global_no' => ++$this->receiptGlobalNo,
            'fiscalised_at' => Carbon::parse('2026-11-05 09:00:00'),
            'submitted_receipt' => [
                'receiptType' => 'FiscalInvoice',
                'receiptCurrency' => $currency,
                'receiptTaxes' => $taxes,
                'receiptPayments' => $payments,
            ],
        ]);
    }

    private function makeVatReceipt(float $amount, string $moneyType, string $currency = 'USD'): void
    {
        $this->makeAcceptedReceipt(
            [['taxID' => 517, 'taxPercent' => 15.5, 'salesAmountWithTax' => $amount, 'taxAmount' => round($amount * 15.5 / 115.5, 2)]],
            [['moneyTypeCode' => $moneyType, 'paymentAmount' => $amount]],
            $currency,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        return app(ZimraCloseDayPayloadBuilder::class)->build($this->device->fresh(), 1, $this->receiptGlobalNo);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function balanceMoneyTypes(array $payload): array
    {
        return collect($payload['fiscalDayCounters'])
            ->where('fiscalCounterType', 'BalanceByMoneyType')
            ->pluck('fiscalCounterMoneyType')
            ->values()
            ->all();
    }

    public function test_cash_is_declared_before_card_and_the_signature_follows_that_order(): void
    {
        $this->makeVatReceipt(5.50, 'Card');
        $this->makeVatReceipt(10.00, 'Cash');

        $payload = $this->build();
        $counters = collect($payload['fiscalDayCounters']);

        $this->assertSame(
            ['SaleByTax', 'SaleTaxByTax', 'BalanceByMoneyType', 'BalanceByMoneyType'],
            $counters->pluck('fiscalCounterType')->all()
        );
        $this->assertSame(['Cash', 'Card'], $this->balanceMoneyTypes($payload));

        $expectedSigningString = '3211'.'2026-11-05'
            .'SALEBYTAXUSD15.501550'
            .'SALETAXBYTAXUSD15.50208'
            .'BALANCEBYMONEYTYPEUSDCASH1000'
            .'BALANCEBYMONEYTYPEUSDCARD550';

        $this->assertSame(
            base64_encode(hash('sha256', $expectedSigningString, true)),
            $payload['fiscalDayDeviceSignature']['hash']
        );

        $publicKey = openssl_pkey_get_details(openssl_pkey_get_private($this->device->private_key_data))['key'];
        $this->assertSame(1, openssl_verify(
            $expectedSigningString,
            base64_decode($payload['fiscalDayDeviceSignature']['signature']),
            $publicKey,
            OPENSSL_ALGO_SHA256
        ));
    }

    public function test_money_types_are_declared_in_zimras_enum_order_with_unknown_types_last(): void
    {
        foreach (['Other', 'Voucher', 'BankTransfer', 'Card', 'Credit', 'Cash', 'Coupon', 'MobileWallet'] as $moneyType) {
            $this->makeVatReceipt(11.55, $moneyType);
        }

        $this->assertSame(
            ['Cash', 'Card', 'MobileWallet', 'Coupon', 'Credit', 'BankTransfer', 'Other', 'Voucher'],
            $this->balanceMoneyTypes($this->build())
        );
    }

    public function test_currencies_are_declared_alphabetically_within_each_counter_type(): void
    {
        $this->makeVatReceipt(46.20, 'Card', 'ZWG');
        $this->makeVatReceipt(11.55, 'Card', 'USD');
        $this->makeVatReceipt(23.10, 'Cash', 'ZWG');
        $this->makeVatReceipt(5.78, 'Cash', 'USD');

        $declared = collect($this->build()['fiscalDayCounters'])
            ->map(fn (array $counter) => implode(' ', array_filter([
                $counter['fiscalCounterType'],
                $counter['fiscalCounterCurrency'],
                $counter['fiscalCounterMoneyType'] ?? null,
            ])))
            ->all();

        $this->assertSame([
            'SaleByTax USD',
            'SaleByTax ZWG',
            'SaleTaxByTax USD',
            'SaleTaxByTax ZWG',
            'BalanceByMoneyType USD Cash',
            'BalanceByMoneyType USD Card',
            'BalanceByMoneyType ZWG Cash',
            'BalanceByMoneyType ZWG Card',
        ], $declared);
    }

    public function test_tax_counters_are_declared_in_ascending_numeric_tax_id_order(): void
    {
        $this->makeAcceptedReceipt(
            [
                ['taxID' => 517, 'taxPercent' => 15.5, 'salesAmountWithTax' => 11.55, 'taxAmount' => 1.55],
                ['taxID' => 10, 'taxPercent' => 5.0, 'salesAmountWithTax' => 10.50, 'taxAmount' => 0.50],
                ['taxID' => 2, 'taxPercent' => 0.0, 'salesAmountWithTax' => 3.00, 'taxAmount' => 0.0],
            ],
            [['moneyTypeCode' => 'Cash', 'paymentAmount' => 25.05]],
        );

        $saleTaxIds = collect($this->build()['fiscalDayCounters'])
            ->where('fiscalCounterType', 'SaleByTax')
            ->pluck('fiscalCounterTaxID')
            ->values()
            ->all();

        $this->assertSame([2, 10, 517], $saleTaxIds);
    }

    public function test_exempt_sales_are_signed_with_an_empty_tax_percent(): void
    {
        $this->makeAcceptedReceipt(
            [['taxID' => 1, 'taxPercent' => null, 'salesAmountWithTax' => 3.00, 'taxAmount' => 0.0]],
            [['moneyTypeCode' => 'Cash', 'paymentAmount' => 3.00]],
        );
        $this->makeAcceptedReceipt(
            [['taxID' => 1, 'salesAmountWithTax' => 2.00, 'taxAmount' => 0.0]],
            [['moneyTypeCode' => 'Cash', 'paymentAmount' => 2.00]],
        );

        $payload = $this->build();
        $saleCounters = collect($payload['fiscalDayCounters'])->where('fiscalCounterType', 'SaleByTax')->values();

        $this->assertCount(1, $saleCounters);
        $this->assertArrayNotHasKey('fiscalCounterTaxPercent', $saleCounters[0]);
        $this->assertEquals(5, $saleCounters[0]['fiscalCounterValue']);
        $this->assertSame(
            base64_encode(hash('sha256', '3211'.'2026-11-05'.'SALEBYTAXUSD500'.'BALANCEBYMONEYTYPEUSDCASH500', true)),
            $payload['fiscalDayDeviceSignature']['hash']
        );
    }
}
