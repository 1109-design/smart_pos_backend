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

    private const DAY_PREFIX = '3211'.'2026-11-05';

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
    private function makeAcceptedReceipt(array $taxes, array $payments, string $currency = 'USD', string $receiptType = 'FiscalInvoice'): void
    {
        ZimraSale::create([
            'transaction_id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'device_id' => '321',
            'status' => 'fiscalised',
            'receipt_global_no' => ++$this->receiptGlobalNo,
            'fiscalised_at' => Carbon::parse('2026-11-05 09:00:00'),
            'submitted_receipt' => [
                'receiptType' => $receiptType,
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

    /**
     * Asserts the payload's hash is of exactly this signing string and that its
     * signature verifies against the device key.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertSignedAs(string $counterString, array $payload): void
    {
        $signingString = self::DAY_PREFIX.$counterString;

        $this->assertSame(
            base64_encode(hash('sha256', $signingString, true)),
            $payload['fiscalDayDeviceSignature']['hash'],
            'Signed counters differ from: '.$counterString
        );

        $publicKey = openssl_pkey_get_details(openssl_pkey_get_private($this->device->private_key_data))['key'];
        $this->assertSame(1, openssl_verify(
            $signingString,
            base64_decode($payload['fiscalDayDeviceSignature']['signature']),
            $publicKey,
            OPENSSL_ALGO_SHA256
        ));
    }

    public function test_cash_is_declared_before_card_and_the_signature_follows_that_order(): void
    {
        $this->makeVatReceipt(5.50, 'Card');
        $this->makeVatReceipt(10.00, 'Cash');

        $payload = $this->build();

        $this->assertSame(
            ['SaleByTax', 'SaleTaxByTax', 'BalanceByMoneyType', 'BalanceByMoneyType'],
            collect($payload['fiscalDayCounters'])->pluck('fiscalCounterType')->all()
        );
        $this->assertSame(['Cash', 'Card'], $this->balanceMoneyTypes($payload));
        $this->assertSignedAs(
            'SALEBYTAXUSD15.501550'
            .'SALETAXBYTAXUSD15.50208'
            .'BALANCEBYMONEYTYPEUSDCASH1000'
            .'BALANCEBYMONEYTYPEUSDCARD550',
            $payload
        );
    }

    public function test_money_types_are_declared_in_zimras_enum_order_with_unknown_types_last(): void
    {
        foreach (['Other', 'Voucher', 'BankTransfer', 'Card', 'Credit', 'Cash', 'Coupon', 'MobileWallet', 'Barter'] as $moneyType) {
            $this->makeVatReceipt(11.55, $moneyType);
        }

        $payload = $this->build();

        $this->assertSame(
            ['Cash', 'Card', 'MobileWallet', 'Coupon', 'Credit', 'BankTransfer', 'Other', 'Barter', 'Voucher'],
            $this->balanceMoneyTypes($payload)
        );
        $this->assertSignedAs(
            'SALEBYTAXUSD15.5010395'
            .'SALETAXBYTAXUSD15.501395'
            .'BALANCEBYMONEYTYPEUSDCASH1155'
            .'BALANCEBYMONEYTYPEUSDCARD1155'
            .'BALANCEBYMONEYTYPEUSDMOBILEWALLET1155'
            .'BALANCEBYMONEYTYPEUSDCOUPON1155'
            .'BALANCEBYMONEYTYPEUSDCREDIT1155'
            .'BALANCEBYMONEYTYPEUSDBANKTRANSFER1155'
            .'BALANCEBYMONEYTYPEUSDOTHER1155'
            .'BALANCEBYMONEYTYPEUSDBARTER1155'
            .'BALANCEBYMONEYTYPEUSDVOUCHER1155',
            $payload
        );
    }

    public function test_currencies_are_declared_alphabetically_within_each_counter_type(): void
    {
        $this->makeVatReceipt(46.20, 'Card', 'ZWG');
        $this->makeVatReceipt(11.55, 'Card', 'USD');
        $this->makeVatReceipt(23.10, 'Cash', 'ZWG');
        $this->makeVatReceipt(5.78, 'Cash', 'USD');

        $payload = $this->build();
        $declared = collect($payload['fiscalDayCounters'])
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
        $this->assertSignedAs(
            'SALEBYTAXUSD15.501733'
            .'SALEBYTAXZWG15.506930'
            .'SALETAXBYTAXUSD15.50233'
            .'SALETAXBYTAXZWG15.50930'
            .'BALANCEBYMONEYTYPEUSDCASH578'
            .'BALANCEBYMONEYTYPEUSDCARD1155'
            .'BALANCEBYMONEYTYPEZWGCASH2310'
            .'BALANCEBYMONEYTYPEZWGCARD4620',
            $payload
        );
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

        $payload = $this->build();
        $saleTaxIds = collect($payload['fiscalDayCounters'])
            ->where('fiscalCounterType', 'SaleByTax')
            ->pluck('fiscalCounterTaxID')
            ->values()
            ->all();

        $this->assertSame([2, 10, 517], $saleTaxIds);
        $this->assertSignedAs(
            'SALEBYTAXUSD0.00300'
            .'SALEBYTAXUSD5.001050'
            .'SALEBYTAXUSD15.501155'
            .'SALETAXBYTAXUSD5.0050'
            .'SALETAXBYTAXUSD15.50155'
            .'BALANCEBYMONEYTYPEUSDCASH2505',
            $payload
        );
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
        $this->assertSignedAs('SALEBYTAXUSD500'.'BALANCEBYMONEYTYPEUSDCASH500', $payload);
    }

    /**
     * Every counter type, both currencies, all tax kinds and all money types in
     * one day, written down in the exact order ZIMRA verifies the signature in.
     */
    private function makeFullDay(bool $reversed = false): void
    {
        $receipts = [
            [[
                ['taxID' => 1, 'salesAmountWithTax' => 5.00, 'taxAmount' => 0.0],
                ['taxID' => 2, 'taxPercent' => 0.0, 'salesAmountWithTax' => 8.00, 'taxAmount' => 0.0],
                ['taxID' => 517, 'taxPercent' => 15.5, 'salesAmountWithTax' => 11.55, 'taxAmount' => 1.55],
            ], [
                ['moneyTypeCode' => 'Card', 'paymentAmount' => 10.00],
                ['moneyTypeCode' => 'Cash', 'paymentAmount' => 14.55],
            ], 'USD', 'FiscalInvoice'],
            [[
                ['taxID' => 517, 'taxPercent' => 15.5, 'salesAmountWithTax' => 23.10, 'taxAmount' => 3.10],
                ['taxID' => 514, 'taxPercent' => 5.0, 'salesAmountWithTax' => 10.50, 'taxAmount' => 0.50],
            ], [['moneyTypeCode' => 'MobileWallet', 'paymentAmount' => 33.60]], 'USD', 'FiscalInvoice'],
            [[['taxID' => 517, 'taxPercent' => 15.5, 'salesAmountWithTax' => -11.55, 'taxAmount' => -1.55]],
                [['moneyTypeCode' => 'Cash', 'paymentAmount' => -11.55]], 'USD', 'CreditNote'],
            [[['taxID' => 517, 'taxPercent' => 15.5, 'salesAmountWithTax' => 23.10, 'taxAmount' => 3.10]],
                [['moneyTypeCode' => 'Credit', 'paymentAmount' => 23.10]], 'USD', 'DebitNote'],
            [[['taxID' => 517, 'taxPercent' => 15.5, 'salesAmountWithTax' => 462.00, 'taxAmount' => 62.00]], [
                ['moneyTypeCode' => 'BankTransfer', 'paymentAmount' => 400.00],
                ['moneyTypeCode' => 'Coupon', 'paymentAmount' => 62.00],
            ], 'ZWG', 'FiscalInvoice'],
            [[['taxID' => 1, 'salesAmountWithTax' => 50.00, 'taxAmount' => 0.0]],
                [['moneyTypeCode' => 'Other', 'paymentAmount' => 50.00]], 'ZWG', 'FiscalInvoice'],
        ];

        foreach ($reversed ? array_reverse($receipts) : $receipts as [$taxes, $payments, $currency, $receiptType]) {
            $this->makeAcceptedReceipt($taxes, $payments, $currency, $receiptType);
        }
    }

    private const FULL_DAY_COUNTERS = 'SALEBYTAXUSD500'
        .'SALEBYTAXUSD0.00800'
        .'SALEBYTAXUSD5.001050'
        .'SALEBYTAXUSD15.503465'
        .'SALEBYTAXZWG5000'
        .'SALEBYTAXZWG15.5046200'
        .'SALETAXBYTAXUSD5.0050'
        .'SALETAXBYTAXUSD15.50465'
        .'SALETAXBYTAXZWG15.506200'
        .'CREDITNOTEBYTAXUSD15.50-1155'
        .'CREDITNOTETAXBYTAXUSD15.50-155'
        .'DEBITNOTEBYTAXUSD15.502310'
        .'DEBITNOTETAXBYTAXUSD15.50310'
        .'BALANCEBYMONEYTYPEUSDCASH300'
        .'BALANCEBYMONEYTYPEUSDCARD1000'
        .'BALANCEBYMONEYTYPEUSDMOBILEWALLET3360'
        .'BALANCEBYMONEYTYPEUSDCREDIT2310'
        .'BALANCEBYMONEYTYPEZWGCOUPON6200'
        .'BALANCEBYMONEYTYPEZWGBANKTRANSFER40000'
        .'BALANCEBYMONEYTYPEZWGOTHER5000';

    public function test_a_day_with_every_kind_of_counter_is_signed_in_zimras_order(): void
    {
        $this->makeFullDay();

        $this->assertSignedAs(self::FULL_DAY_COUNTERS, $this->build());
    }

    public function test_the_order_receipts_were_made_in_does_not_change_the_signature(): void
    {
        $this->makeFullDay(reversed: true);

        $this->assertSignedAs(self::FULL_DAY_COUNTERS, $this->build());
    }

    public function test_a_credit_note_on_its_own_is_signed_with_negative_cents(): void
    {
        $this->makeAcceptedReceipt(
            [['taxID' => 517, 'taxPercent' => 15.5, 'salesAmountWithTax' => -11.55, 'taxAmount' => -1.55]],
            [['moneyTypeCode' => 'Card', 'paymentAmount' => -11.55]],
            'USD',
            'CreditNote',
        );

        $this->assertSignedAs(
            'CREDITNOTEBYTAXUSD15.50-1155'
            .'CREDITNOTETAXBYTAXUSD15.50-155'
            .'BALANCEBYMONEYTYPEUSDCARD-1155',
            $this->build()
        );
    }

    public function test_counters_that_net_to_zero_are_left_out(): void
    {
        $this->makeVatReceipt(11.55, 'Cash');
        $this->makeAcceptedReceipt(
            [['taxID' => 517, 'taxPercent' => 15.5, 'salesAmountWithTax' => -11.55, 'taxAmount' => -1.55]],
            [['moneyTypeCode' => 'Cash', 'paymentAmount' => -11.55]],
            'USD',
            'CreditNote',
        );

        $payload = $this->build();

        $this->assertSame([], $this->balanceMoneyTypes($payload));
        $this->assertSignedAs(
            'SALEBYTAXUSD15.501155'
            .'SALETAXBYTAXUSD15.50155'
            .'CREDITNOTEBYTAXUSD15.50-1155'
            .'CREDITNOTETAXBYTAXUSD15.50-155',
            $payload
        );
    }

    public function test_many_small_amounts_add_up_to_the_exact_cent(): void
    {
        foreach (range(1, 30) as $ignored) {
            $this->makeAcceptedReceipt(
                [['taxID' => 517, 'taxPercent' => 15.5, 'salesAmountWithTax' => 0.10, 'taxAmount' => 0.01]],
                [['moneyTypeCode' => 'Cash', 'paymentAmount' => 0.10]],
            );
        }
        $this->makeVatReceipt(0.20, 'Card');

        $this->assertSignedAs(
            'SALEBYTAXUSD15.50320'
            .'SALETAXBYTAXUSD15.5033'
            .'BALANCEBYMONEYTYPEUSDCASH300'
            .'BALANCEBYMONEYTYPEUSDCARD20',
            $this->build()
        );
    }

    public function test_two_taxes_with_the_same_percent_keep_their_own_counters(): void
    {
        $this->makeAcceptedReceipt(
            [
                ['taxID' => 600, 'taxPercent' => 15.0, 'salesAmountWithTax' => 23.00, 'taxAmount' => 3.00],
                ['taxID' => 3, 'taxPercent' => 15.0, 'salesAmountWithTax' => 11.50, 'taxAmount' => 1.50],
            ],
            [['moneyTypeCode' => 'Cash', 'paymentAmount' => 34.50]],
        );

        $payload = $this->build();

        $this->assertSame(
            [3, 600],
            collect($payload['fiscalDayCounters'])->where('fiscalCounterType', 'SaleByTax')->pluck('fiscalCounterTaxID')->values()->all()
        );
        $this->assertSignedAs(
            'SALEBYTAXUSD15.001150'
            .'SALEBYTAXUSD15.002300'
            .'SALETAXBYTAXUSD15.00150'
            .'SALETAXBYTAXUSD15.00300'
            .'BALANCEBYMONEYTYPEUSDCASH3450',
            $payload
        );
    }
}
