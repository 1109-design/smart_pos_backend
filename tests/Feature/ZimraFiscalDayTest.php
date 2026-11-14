<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraFiscalDay;
use App\Models\Zimra\ZimraSale;
use App\Services\Zimra\ZimraFiscalDayService;
use App\Services\Zimra\ZimraSalesService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZimraFiscalDayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config([
            'zimra.failure_alert_emails' => ['owner@example.com'],
            'zimra.fiscalisation.enabled' => true,
            'zimra.fiscalisation.allowed_environments' => ['testing'],
            'zimra.fiscalisation.allowed_hosts' => [],
        ]);
    }

    private function makeDevice(array $attributes = []): ZimraDevice
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKeyPem);

        return ZimraDevice::create($attributes + [
            'business_id' => 'biz-1',
            'tin' => '1234567890',
            'device_id' => '321',
            'is_active' => true,
            'status' => 'active',
            'private_key_data' => $privateKeyPem,
            'fiscal_day_opened_at' => Carbon::parse('2026-11-05 06:00:00'),
            'applicable_taxes' => [['taxID' => 3, 'taxPercent' => 15.0, 'taxName' => 'VAT 15%']],
            'tax_codes' => [3 => 'A'],
        ]);
    }

    private function makeAcceptedReceipt(string $fiscalisedAt, float $amount = 115.0): void
    {
        ZimraSale::create([
            'transaction_id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'device_id' => '321',
            'status' => 'fiscalised',
            'receipt_global_no' => random_int(1, 100000),
            'fiscalised_at' => Carbon::parse($fiscalisedAt),
            'submitted_receipt' => [
                'receiptCurrency' => 'USD',
                'receiptTaxes' => [['taxID' => 3, 'taxPercent' => 15.0, 'salesAmountWithTax' => $amount, 'taxAmount' => 15.0]],
                'receiptPayments' => [['moneyTypeCode' => 'Cash', 'paymentAmount' => $amount]],
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses  GetStatus replies, in order. The last one repeats.
     */
    private function fakeZimra(array $statuses, int $closeDayCode = 200): void
    {
        $calls = 0;

        Http::fake(function (Request $request) use ($statuses, $closeDayCode, &$calls) {
            if (str_contains($request->url(), 'GetStatus')) {
                return Http::response($statuses[min($calls++, count($statuses) - 1)]);
            }

            if (str_contains($request->url(), 'CloseDay')) {
                return Http::response(
                    $closeDayCode === 200 ? ['operationID' => 'op-1'] : ['title' => 'Refused'],
                    $closeDayCode
                );
            }

            return Http::response([], 404);
        });
    }

    private function closeRequests(): int
    {
        return count(Http::recorded(fn (Request $request) => str_contains($request->url(), 'CloseDay')));
    }

    private function alertsSent(): int
    {
        return Mail::getSymfonyTransport()->messages()->count();
    }

    private function service(): ZimraFiscalDayService
    {
        return app(ZimraFiscalDayService::class);
    }

    public function test_a_confirmed_close_keeps_what_was_declared_and_what_zimra_replied(): void
    {
        $device = $this->makeDevice();
        $this->makeAcceptedReceipt('2026-11-05 09:00:00');
        $this->fakeZimra([
            ['fiscalDayStatus' => 'FiscalDayOpened', 'lastFiscalDayNo' => 7, 'lastReceiptCounter' => 1, 'lastReceiptGlobalNo' => 40],
            [
                'fiscalDayStatus' => 'FiscalDayClosed',
                'lastFiscalDayNo' => 7,
                'fiscalDayClosed' => '2026-11-05T23:45:10',
                'fiscalDayServerSignature' => ['signature' => 'server-sig', 'hash' => 'h'],
            ],
        ]);

        $result = $this->service()->close($device);

        $this->assertSame(ZimraFiscalDayService::CLOSED, $result['outcome']);

        $day = ZimraFiscalDay::firstOrFail();
        $this->assertSame(ZimraFiscalDay::STATUS_CLOSED, $day->status);
        $this->assertSame(7, $day->fiscal_day_no);
        $this->assertSame(1, $day->receipt_counter);
        $this->assertSame(40, (int) $day->last_receipt_global_no);
        $this->assertSame('server-sig', $day->server_signature);
        $this->assertSame('op-1', $day->close_response['operationID']);
        $this->assertCount(1, $day->close_attempts);
        $this->assertSame(
            115.0,
            (float) collect($day->close_counters)->firstWhere('fiscalCounterType', 'SaleByTax')['fiscalCounterValue']
        );
        $this->assertSame('2026-11-05 21:45:10', $day->closed_at->utc()->toDateTimeString());
        $this->assertNull($device->fresh()->fiscal_day_opened_at);
        $this->assertSame(0, $this->alertsSent());
    }

    public function test_a_rejected_close_keeps_the_day_open_and_alerts_once(): void
    {
        $device = $this->makeDevice();
        $this->fakeZimra([
            ['fiscalDayStatus' => 'FiscalDayOpened', 'lastFiscalDayNo' => 7],
            ['fiscalDayStatus' => 'FiscalDayCloseFailed', 'lastFiscalDayNo' => 7, 'fiscalDayClosingErrorCode' => 'CountersMismatch'],
        ]);

        $result = $this->service()->close($device);

        $this->assertSame(ZimraFiscalDayService::FAILED, $result['outcome']);
        $this->assertStringContainsString('CountersMismatch', $result['message']);

        $day = ZimraFiscalDay::firstOrFail();
        $this->assertSame(ZimraFiscalDay::STATUS_CLOSE_FAILED, $day->status);
        $this->assertSame('CountersMismatch', $day->closing_error_code);
        $this->assertNotNull($device->fresh()->fiscal_day_opened_at);
        $this->assertSame(1, $this->alertsSent());

        $this->service()->confirm($device->fresh());
        $this->assertSame(1, $this->alertsSent());
    }

    public function test_closing_a_day_settles_earlier_days_closed_in_the_portal(): void
    {
        $device = $this->makeDevice();
        $portalClosed = ZimraFiscalDay::recordFor($device, 5);
        $this->fakeZimra([
            ['fiscalDayStatus' => 'FiscalDayOpened', 'lastFiscalDayNo' => 7],
            ['fiscalDayStatus' => 'FiscalDayClosed', 'lastFiscalDayNo' => 7],
        ]);

        $this->service()->close($device);

        $this->assertSame(ZimraFiscalDay::STATUS_CLOSED, $portalClosed->fresh()->status);
        $this->assertNull($portalClosed->fresh()->closed_at);
    }

    public function test_retrying_a_rejected_close_totals_the_days_receipts_and_keeps_both_attempts(): void
    {
        $device = $this->makeDevice();
        $this->makeAcceptedReceipt('2026-11-05 09:00:00');
        $this->fakeZimra([
            ['fiscalDayStatus' => 'FiscalDayOpened', 'lastFiscalDayNo' => 7],
            ['fiscalDayStatus' => 'FiscalDayCloseFailed', 'lastFiscalDayNo' => 7, 'fiscalDayClosingErrorCode' => 'CountersMismatch'],
            ['fiscalDayStatus' => 'FiscalDayCloseFailed', 'lastFiscalDayNo' => 7, 'fiscalDayClosingErrorCode' => 'CountersMismatch'],
            ['fiscalDayStatus' => 'FiscalDayClosed', 'lastFiscalDayNo' => 7],
        ]);

        $this->service()->close($device);

        // The retry happens the next day: it must still total yesterday's receipt.
        Carbon::setTestNow('2026-11-06 21:45:00');
        $result = $this->service()->close($device->fresh());
        Carbon::setTestNow();

        $this->assertSame(ZimraFiscalDayService::CLOSED, $result['outcome']);

        $day = ZimraFiscalDay::firstOrFail();
        $this->assertSame(ZimraFiscalDay::STATUS_CLOSED, $day->status);
        $this->assertNull($day->closing_error_code);
        $this->assertCount(2, $day->close_attempts);
        $this->assertSame(
            115.0,
            (float) collect($day->close_counters)->firstWhere('fiscalCounterType', 'SaleByTax')['fiscalCounterValue']
        );
        $this->assertSame(2, $this->closeRequests());
    }

    public function test_a_retry_that_zimra_refuses_outright_stays_failed_on_later_checks(): void
    {
        $device = $this->makeDevice();
        $this->fakeZimra([['fiscalDayStatus' => 'FiscalDayOpened', 'lastFiscalDayNo' => 7]], closeDayCode: 422);

        $this->assertSame(ZimraFiscalDayService::FAILED, $this->service()->close($device)['outcome']);
        $this->assertSame(ZimraFiscalDayService::FAILED, $this->service()->confirm($device->fresh())['outcome']);

        $this->assertSame(ZimraFiscalDay::STATUS_CLOSE_FAILED, ZimraFiscalDay::firstOrFail()->status);
        $this->assertNotNull($device->fresh()->fiscal_day_opened_at);
        $this->assertSame(1, $this->alertsSent());
    }

    public function test_an_unconfirmed_close_is_settled_by_the_scheduled_check(): void
    {
        $device = $this->makeDevice();
        $this->fakeZimra([
            ['fiscalDayStatus' => 'FiscalDayOpened', 'lastFiscalDayNo' => 7],
            ['fiscalDayStatus' => 'FiscalDayCloseInitiated', 'lastFiscalDayNo' => 7],
            ['fiscalDayStatus' => 'FiscalDayCloseInitiated', 'lastFiscalDayNo' => 7],
            ['fiscalDayStatus' => 'FiscalDayCloseInitiated', 'lastFiscalDayNo' => 7],
            ['fiscalDayStatus' => 'FiscalDayClosed', 'lastFiscalDayNo' => 7],
        ]);

        $this->assertSame(ZimraFiscalDayService::PENDING, $this->service()->close($device)['outcome']);
        $this->assertSame(ZimraFiscalDay::STATUS_CLOSE_SUBMITTED, ZimraFiscalDay::firstOrFail()->status);
        $this->assertNotNull($device->fresh()->fiscal_day_opened_at);

        $this->artisan('zimra:fiscal-day confirm --all')->assertSuccessful();

        $this->assertSame(ZimraFiscalDay::STATUS_CLOSED, ZimraFiscalDay::firstOrFail()->status);
        $this->assertNull($device->fresh()->fiscal_day_opened_at);
    }

    public function test_a_close_stuck_unconfirmed_alerts_after_two_hours_only(): void
    {
        $device = $this->makeDevice();
        ZimraFiscalDay::create([
            'business_id' => 'biz-1',
            'device_id' => '321',
            'fiscal_day_no' => 7,
            'status' => ZimraFiscalDay::STATUS_CLOSE_SUBMITTED,
            'close_submitted_at' => now()->subHour(),
        ]);
        $this->fakeZimra([['fiscalDayStatus' => 'FiscalDayCloseInitiated', 'lastFiscalDayNo' => 7]]);

        $this->service()->confirm($device);
        $this->assertSame(0, $this->alertsSent());

        Carbon::setTestNow(now()->addHours(2));
        $this->service()->confirm($device);
        $this->service()->confirm($device);
        Carbon::setTestNow();

        $this->assertSame(1, $this->alertsSent());
    }

    public function test_a_new_day_opened_by_a_sale_also_settles_the_previous_close(): void
    {
        $device = $this->makeDevice();
        ZimraFiscalDay::create([
            'business_id' => 'biz-1',
            'device_id' => '321',
            'fiscal_day_no' => 7,
            'status' => ZimraFiscalDay::STATUS_CLOSE_SUBMITTED,
        ]);
        $this->fakeZimra([['fiscalDayStatus' => 'FiscalDayOpened', 'lastFiscalDayNo' => 8]]);

        $this->service()->confirm($device);

        $this->assertSame(ZimraFiscalDay::STATUS_CLOSED, ZimraFiscalDay::firstOrFail()->status);
        $this->assertNotNull($device->fresh()->fiscal_day_opened_at);
    }

    public function test_a_close_zimra_already_started_is_not_sent_again(): void
    {
        $device = $this->makeDevice();
        $this->fakeZimra([
            ['fiscalDayStatus' => 'FiscalDayCloseInitiated', 'lastFiscalDayNo' => 7],
            ['fiscalDayStatus' => 'FiscalDayClosed', 'lastFiscalDayNo' => 7],
        ]);

        $this->assertSame(ZimraFiscalDayService::CLOSED, $this->service()->close($device)['outcome']);
        $this->assertSame(0, $this->closeRequests());
        $this->assertSame(ZimraFiscalDay::STATUS_CLOSED, ZimraFiscalDay::firstOrFail()->status);
    }

    public function test_a_blocked_environment_contacts_nothing_and_records_nothing(): void
    {
        config(['zimra.fiscalisation.allowed_environments' => ['production']]);
        $device = $this->makeDevice();
        Http::fake();

        $this->assertSame(ZimraFiscalDayService::BLOCKED, $this->service()->close($device)['outcome']);

        Http::assertNothingSent();
        $this->assertSame(0, ZimraFiscalDay::count());
        $this->assertSame(0, $this->alertsSent());
    }

    public function test_the_nightly_close_alerts_when_zimra_cannot_be_reached(): void
    {
        $this->makeDevice();
        Http::fake(['*' => Http::response('Unavailable', 503)]);

        $this->artisan('zimra:fiscal-day close --all')->assertFailed();

        $this->assertSame(1, $this->alertsSent());
    }

    public function test_the_close_command_contacts_nothing_in_a_blocked_environment(): void
    {
        config(['zimra.fiscalisation.allowed_environments' => ['production']]);
        $this->makeDevice();
        Http::fake();

        $this->artisan('zimra:fiscal-day close 321')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_the_scheduled_check_contacts_zimra_only_for_unconfirmed_closes(): void
    {
        $this->makeDevice();
        Http::fake();

        $this->artisan('zimra:fiscal-day confirm --all')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_close_signature_uses_the_harare_date_the_day_opened_on(): void
    {
        // 22:30 UTC on the 4th is 00:30 on the 5th in Harare.
        $device = $this->makeDevice(['fiscal_day_opened_at' => Carbon::parse('2026-11-04 22:30:00')]);
        $this->fakeZimra([
            ['fiscalDayStatus' => 'FiscalDayOpened', 'lastFiscalDayNo' => 7],
            ['fiscalDayStatus' => 'FiscalDayClosed', 'lastFiscalDayNo' => 7],
        ]);

        $this->service()->close($device);

        $this->assertSame(
            base64_encode(hash('sha256', '32172026-11-05', true)),
            ZimraFiscalDay::firstOrFail()->close_payload['fiscalDayDeviceSignature']['hash']
        );
    }

    public function test_a_fiscalised_sale_is_linked_to_its_fiscal_day(): void
    {
        $device = $this->makeDevice();
        Business::create(['id' => 'biz-1', 'name' => 'Test Shop', 'fiscalisation_enabled' => true]);
        $transaction = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'user_id' => (string) Str::uuid(),
            'subtotal' => 100,
            'tax_total' => 15,
            'total' => 115,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => '202611-A1B2-1',
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

        Http::fake(function (Request $request) {
            return str_contains($request->url(), 'GetStatus')
                ? Http::response(['fiscalDayStatus' => 'FiscalDayOpened', 'lastFiscalDayNo' => 7, 'lastReceiptGlobalNo' => 40])
                : Http::response(['receiptID' => 900, 'serverDate' => '2026-11-05T10:00:00', 'receiptServerSignature' => ['signature' => 'sig']]);
        });

        $result = app(ZimraSalesService::class)->processQueued($transaction);

        $this->assertTrue($result['fiscalised'], $result['message']);

        $sale = ZimraSale::where('transaction_id', $transaction->id)->firstOrFail();
        $this->assertSame(7, (int) $sale->fiscal_day_no);

        $day = ZimraFiscalDay::firstOrFail();
        $this->assertSame(7, $day->fiscal_day_no);
        $this->assertSame(ZimraFiscalDay::STATUS_OPEN, $day->status);
        $this->assertSame($device->fiscal_day_opened_at->toDateTimeString(), $day->opened_at->toDateTimeString());
        $this->assertTrue($day->sales->contains($sale));
    }
}
