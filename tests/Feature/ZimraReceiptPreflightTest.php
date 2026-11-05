<?php

namespace Tests\Feature;

use App\Jobs\ProcessZimraFiscalisationJob;
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
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZimraReceiptPreflightTest extends TestCase
{
    use RefreshDatabase;

    private ZimraDevice $device;

    /** @var array<string, mixed> What the fake ZIMRA's GetStatus reports. */
    private array $status = ['fiscalDayStatus' => 'FiscalDayOpened', 'lastFiscalDayNo' => 7, 'lastReceiptGlobalNo' => 46];

    /** @var list<list<string>> */
    private array $redCodesByReceipt = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config([
            'mail.default' => 'array',
            'zimra.failure_alert_emails' => ['owner@example.com'],
            'zimra.fiscalisation.enabled' => true,
            'zimra.fiscalisation.allowed_environments' => ['testing'],
            'zimra.fiscalisation.allowed_hosts' => [],
        ]);

        Business::create(['id' => 'biz-1', 'name' => 'Fiscal Shop', 'fiscalisation_enabled' => true, 'tin' => '1234567890']);

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
                return Http::response($this->status);
            }
            if (str_contains($request->url(), 'OpenDay')) {
                $this->status = [
                    'fiscalDayStatus' => 'FiscalDayOpened',
                    'lastFiscalDayNo' => (int) $this->status['lastFiscalDayNo'] + 1,
                    'lastReceiptGlobalNo' => $this->status['lastReceiptGlobalNo'],
                    'lastReceiptCounter' => 0,
                ];

                return Http::response(['fiscalDayNo' => $this->status['lastFiscalDayNo']]);
            }
            if (str_contains($request->url(), 'CloseDay')) {
                return Http::response(['operationID' => 'op-1']);
            }

            $this->status['lastReceiptGlobalNo']++;
            if (isset($this->status['lastReceiptCounter'])) {
                $this->status['lastReceiptCounter']++;
            }

            return Http::response([
                'receiptID' => 11939000 + $this->status['lastReceiptGlobalNo'],
                'serverDate' => now()->toIso8601String(),
                'receiptServerSignature' => ['signature' => 'server-signature'],
                'validationErrors' => array_map(
                    fn (string $code) => ['validationErrorCode' => $code, 'validationErrorColor' => 'Red'],
                    array_shift($this->redCodesByReceipt) ?? []
                ),
            ]);
        });
    }

    private function makeSale(string $number, float $total = 115): Transaction
    {
        $sale = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'user_id' => (string) Str::uuid(),
            'subtotal' => $total,
            'tax_total' => $total * 15 / 115,
            'total' => $total,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => $number,
            'fiscalisation_requested' => true,
        ]);
        TransactionItem::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $sale->id,
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Widget',
            'quantity' => 1,
            'unit_price' => $total,
            'tax_amount' => $total * 15 / 115,
            'line_total' => $total,
        ]);
        Payment::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $sale->id,
            'method' => 'cash',
            'amount' => max($total, 1),
            'currency_code' => 'USD',
            'base_equivalent' => max($total, 1),
        ]);

        return $sale;
    }

    /**
     * A receipt already in the local records, without sending anything.
     *
     * @param  list<string>  $redCodes
     */
    private function recordReceipt(int $globalNo, Carbon $at, array $redCodes = []): ZimraSale
    {
        return ZimraSale::create([
            'transaction_id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'device_id' => '321',
            'status' => 'fiscalised',
            'zimra_receipt_id' => (string) (11939000 + $globalNo),
            'receipt_global_no' => $globalNo,
            'device_signature_hash' => 'hash-'.$globalNo,
            'fiscalised_at' => $at,
            'zimra_response' => ['validationErrors' => array_map(
                fn (string $code) => ['validationErrorCode' => $code, 'validationErrorColor' => 'Red'],
                $redCodes
            )],
        ]);
    }

    private function service(): ZimraSalesService
    {
        return app(ZimraSalesService::class);
    }

    private function days(): ZimraFiscalDayService
    {
        return app(ZimraFiscalDayService::class);
    }

    private function callsTo(string $endpoint): int
    {
        return count(Http::recorded(fn (Request $request) => str_contains($request->url(), $endpoint)));
    }

    private function emailsSent(): int
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count();
    }

    public function test_a_lagging_status_holds_the_receipt_and_it_goes_on_the_next_run(): void
    {
        $this->recordReceipt(47, now());
        $this->status['lastReceiptGlobalNo'] = 46;
        $sale = $this->makeSale('sale-1');

        $result = $this->service()->processQueued($sale);

        $this->assertTrue($result['held']);
        $this->assertSame(0, $this->callsTo('SubmitReceipt'));
        $this->assertSame(0, ZimraSale::where('transaction_id', $sale->id)->value('retry_count'));
        $this->assertSame('pending', $sale->fresh()->fiscal_status);

        $this->status['lastReceiptGlobalNo'] = 47;
        $this->service()->processQueued($sale->fresh());

        $this->assertSame('fiscalised', $sale->fresh()->fiscal_status);
        $this->assertNull($sale->fresh()->fiscal_error);
        $zimraSale = ZimraSale::where('transaction_id', $sale->id)->first();
        $this->assertSame(48, (int) $zimraSale->receipt_global_no);
        $this->assertSame('sale-1', $zimraSale->fiscal_invoice_no);
    }

    public function test_receipts_in_the_same_second_get_increasing_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00.500', 'UTC'));
        $this->device->update(['fiscal_day_opened_at' => now()->subHour()]);

        foreach (['sale-1', 'sale-2', 'sale-3'] as $number) {
            $this->service()->processQueued($this->makeSale($number));
        }

        $dates = ZimraSale::orderBy('receipt_global_no')->get()->map(fn ($z) => $z->submitted_receipt['receiptDate'])->all();
        $this->assertSame(['2026-11-06T12:00:00', '2026-11-06T12:00:01', '2026-11-06T12:00:02'], $dates);

        Carbon::setTestNow();
    }

    public function test_a_zero_priced_line_is_never_sent_and_does_not_block_the_queue(): void
    {
        $free = $this->makeSale('free-1', 0);
        $paid = $this->makeSale('paid-1');
        $this->service()->queueFiscalisation($free);
        $this->service()->queueFiscalisation($paid);

        (new ProcessZimraFiscalisationJob('321'))->handle($this->service());

        $this->assertSame(1, $this->callsTo('SubmitReceipt'));
        $this->assertSame('failed', $free->fresh()->fiscal_status);
        $this->assertStringContainsString('RCPT022', $free->fresh()->fiscal_error);
        $this->assertSame('failed', ZimraSale::where('transaction_id', $free->id)->value('status'));
        $this->assertSame('fiscalised', $paid->fresh()->fiscal_status);
    }

    public function test_the_first_receipt_of_a_day_carries_no_hash_and_ignores_stale_rows(): void
    {
        $this->recordReceipt(47, now()->subMinute(), ['RCPT020']);
        $this->status['lastReceiptGlobalNo'] = 47;
        $this->status['lastReceiptCounter'] = 0;

        $signed = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$signed) {
            if ($log->message === 'ZIMRA signing string') {
                $signed[] = $log->context['string_to_sign'];
            }
        });

        $sale = $this->makeSale('sale-1');
        $this->service()->processQueued($sale);

        $this->assertSame('fiscalised', $sale->fresh()->fiscal_status);
        $this->assertCount(1, $signed);
        $this->assertStringNotContainsString('hash-47', $signed[0]);
    }

    public function test_later_receipts_chain_off_the_one_before(): void
    {
        $this->status['lastReceiptCounter'] = 1;
        $this->status['lastReceiptGlobalNo'] = 47;
        $this->recordReceipt(47, now());

        $signed = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$signed) {
            if ($log->message === 'ZIMRA signing string') {
                $signed[] = $log->context['string_to_sign'];
            }
        });

        $this->service()->processQueued($this->makeSale('sale-1'));

        $this->assertStringEndsWith('hash-47', $signed[0]);
        $this->assertSame(2, ZimraSale::where('fiscal_invoice_no', 'sale-1')->first()->submitted_receipt['receiptCounter']);
    }

    public function test_numbers_missing_from_an_earlier_day_do_not_block(): void
    {
        foreach ([47, 48, 50, 52] as $globalNo) {
            $this->recordReceipt($globalNo, now()->subDay(), $globalNo === 52 ? ['RCPT012'] : []);
        }
        $this->status['lastReceiptGlobalNo'] = 52;
        $sale = $this->makeSale('sale-1');

        $this->service()->processQueued($sale);

        $this->assertSame('fiscalised', $sale->fresh()->fiscal_status);
        $this->assertSame(53, (int) ZimraSale::where('transaction_id', $sale->id)->value('receipt_global_no'));
    }

    public function test_a_gap_in_todays_chain_holds(): void
    {
        $this->recordReceipt(47, now());
        $this->recordReceipt(49, now());
        $this->status['lastReceiptGlobalNo'] = 49;

        $result = $this->service()->processQueued($this->makeSale('sale-1'));

        $this->assertTrue($result['held']);
        $this->assertSame(0, $this->callsTo('SubmitReceipt'));
    }

    public function test_zimra_being_ahead_of_todays_records_holds_rather_than_signing_a_wrong_hash(): void
    {
        $this->recordReceipt(47, now());
        $this->status['lastReceiptGlobalNo'] = 48;
        $this->status['lastReceiptCounter'] = 2;

        $result = $this->service()->processQueued($this->makeSale('sale-1'));

        $this->assertTrue($result['held']);
        $this->assertSame(0, $this->callsTo('SubmitReceipt'));
    }

    public function test_an_unusable_device_or_day_holds(): void
    {
        $sale = $this->makeSale('sale-1');

        $this->status['fiscalDayStatus'] = 'FiscalDayCloseFailed';
        $this->assertTrue($this->service()->processQueued($sale)['held']);
        $this->status['fiscalDayStatus'] = 'FiscalDayOpened';

        $this->device->update(['fiscal_day_max_hours' => 1, 'fiscal_day_opened_at' => now()->subHours(2)]);
        $this->assertTrue($this->service()->processQueued($sale->fresh())['held']);
        $this->device->update(['fiscal_day_max_hours' => null, 'fiscal_day_opened_at' => now()->subHour()]);

        $key = $this->device->private_key_data;
        $this->device->update(['private_key_data' => null]);
        $this->assertTrue($this->service()->processQueued($sale->fresh())['held']);
        $this->device->update(['private_key_data' => $key, 'applicable_taxes' => null, 'tax_codes' => [3 => 'A']]);
        $this->assertTrue($this->service()->processQueued($sale->fresh())['held']);

        $this->assertSame(0, $this->callsTo('SubmitReceipt'));
    }

    public function test_one_hold_stops_the_retry_run_for_that_device(): void
    {
        Queue::fake();
        $this->status['fiscalDayStatus'] = 'FiscalDayCloseInitiated';
        $this->service()->queueFiscalisation($this->makeSale('sale-1'));
        $this->service()->queueFiscalisation($this->makeSale('sale-2'));

        $results = $this->service()->retryFailedFiscalisations();

        $this->assertSame(1, $results['attempted']);
        $this->assertSame(1, $this->callsTo('GetStatus'));
    }

    public function test_reprobe_leaves_a_healthy_idle_device_alone(): void
    {
        $this->recordReceipt(47, now());

        $this->days()->reprobe($this->device);

        Http::assertNothingSent();
    }

    public function test_reprobe_flags_an_open_day_with_a_red_receipt_once_and_never_tries_to_close_it(): void
    {
        $this->recordReceipt(47, now(), ['RCPT020']);
        $this->status['lastReceiptGlobalNo'] = 47;

        $this->days()->reprobe($this->device);
        $this->days()->reprobe($this->device);

        $day = ZimraFiscalDay::where('fiscal_day_no', 7)->firstOrFail();
        $this->assertTrue($day->requiresManualClose());
        $this->assertStringContainsString('Public Portal', $day->error_message);
        $this->assertSame(0, $this->callsTo('CloseDay'));
        $this->assertSame(1, $this->emailsSent());
    }

    public function test_the_nightly_close_does_not_send_a_day_with_a_red_receipt(): void
    {
        $this->recordReceipt(47, now(), ['RCPT020']);
        $this->status['lastReceiptGlobalNo'] = 47;

        $result = $this->days()->close($this->device);

        $this->assertSame(ZimraFiscalDayService::FAILED, $result['outcome']);
        $this->assertSame(0, $this->callsTo('CloseDay'));
        $this->assertTrue(ZimraFiscalDay::where('fiscal_day_no', 7)->firstOrFail()->requiresManualClose());
    }

    public function test_reprobe_never_retries_a_close_zimra_refused_for_validation_errors(): void
    {
        foreach (['ReceiptsWithValidationErrors', 2] as $code) {
            ZimraFiscalDay::query()->delete();
            $this->status = ['fiscalDayStatus' => 'FiscalDayCloseFailed', 'lastFiscalDayNo' => 7, 'lastReceiptGlobalNo' => 46, 'fiscalDayClosingErrorCode' => $code];
            $this->service()->queueFiscalisation($this->makeSale('sale-'.$code));

            $this->days()->reprobe($this->device);

            $this->assertSame(0, $this->callsTo('CloseDay'));
            $this->assertTrue(ZimraFiscalDay::where('fiscal_day_no', 7)->firstOrFail()->requiresManualClose());
        }
    }

    public function test_reprobe_resends_another_failed_close_then_gives_up(): void
    {
        $this->status = ['fiscalDayStatus' => 'FiscalDayCloseFailed', 'lastFiscalDayNo' => 7, 'lastReceiptGlobalNo' => 46, 'fiscalDayClosingErrorCode' => 'CountersMismatch'];
        ZimraFiscalDay::recordFor($this->device, 7)->update(['status' => ZimraFiscalDay::STATUS_CLOSE_FAILED]);

        for ($run = 1; $run <= 5; $run++) {
            $this->days()->reprobe($this->device);
        }

        $this->assertSame(3, $this->callsTo('CloseDay'));
        $this->assertTrue(ZimraFiscalDay::where('fiscal_day_no', 7)->firstOrFail()->requiresManualClose());
    }

    public function test_reprobe_closes_an_expired_day(): void
    {
        $this->device->update(['fiscal_day_max_hours' => 1, 'fiscal_day_opened_at' => now()->subHours(2)]);

        $this->days()->reprobe($this->device);

        $this->assertSame(1, $this->callsTo('CloseDay'));
    }

    public function test_reprobe_queues_the_backlog_once_the_day_is_closed(): void
    {
        ZimraFiscalDay::recordFor($this->device, 7)->update(['closing_error_code' => ZimraFiscalDay::MANUAL_CLOSE_REQUIRED]);
        $this->status['fiscalDayStatus'] = 'FiscalDayClosed';
        Queue::fake();

        $this->days()->reprobe($this->device);

        Queue::assertPushed(ProcessZimraFiscalisationJob::class, 1);
    }

    public function test_a_day_closed_in_the_portal_clears_and_the_backlog_is_sent(): void
    {
        Queue::fake();
        $this->redCodesByReceipt = [['RCPT013']];
        $first = $this->makeSale('sale-1');
        $this->service()->processQueued($first);
        $held = $this->makeSale('sale-2');
        $this->service()->queueFiscalisation($held);

        // Stuck: flagged, held, and nothing sent.
        $this->days()->reprobe($this->device);
        (new ProcessZimraFiscalisationJob('321'))->handle($this->service());
        $this->assertSame(1, $this->callsTo('SubmitReceipt'));
        $this->assertSame('pending', $held->fresh()->fiscal_status);
        $this->assertTrue(ZimraFiscalDay::where('fiscal_day_no', 7)->firstOrFail()->requiresManualClose());

        // The owner closes it in the Public Portal.
        $this->status['fiscalDayStatus'] = 'FiscalDayClosed';
        $this->travel(5)->seconds();
        $this->days()->reprobe($this->device);

        $day = ZimraFiscalDay::where('fiscal_day_no', 7)->firstOrFail();
        $this->assertSame(ZimraFiscalDay::STATUS_CLOSED, $day->status);
        $this->assertFalse($day->requiresManualClose());

        (new ProcessZimraFiscalisationJob('321'))->handle($this->service());

        $this->assertSame(1, $this->callsTo('OpenDay'));
        $this->assertSame(2, $this->callsTo('SubmitReceipt'));
        $this->assertSame('fiscalised', $held->fresh()->fiscal_status);
        $this->assertNull($held->fresh()->fiscal_error);
        $this->assertSame(48, (int) ZimraSale::where('transaction_id', $held->id)->value('receipt_global_no'));
        $this->assertSame(1, ZimraSale::where('transaction_id', $held->id)->first()->submitted_receipt['receiptCounter']);

        // The rejected receipt itself was left exactly as it was.
        $this->assertSame(47, (int) ZimraSale::where('transaction_id', $first->id)->value('receipt_global_no'));
        $this->assertSame('failed', $first->fresh()->fiscal_status);
    }
}
