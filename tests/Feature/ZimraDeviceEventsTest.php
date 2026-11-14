<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraDeviceEvent;
use App\Services\Zimra\ZimraClient;
use App\Services\Zimra\ZimraDeviceEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every contact with ZIMRA lands in the device log — failures included, which
 * until now only reached the text log — and nothing secret is ever stored.
 */
class ZimraDeviceEventsTest extends TestCase
{
    use RefreshDatabase;

    private ZimraDevice $device;

    private bool $pingIsDown = false;

    private bool $openIsRefused = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'zimra.fiscalisation.enabled' => true,
            'zimra.fiscalisation.allowed_environments' => ['testing'],
            'zimra.fiscalisation.allowed_hosts' => [],
            'zimra.failure_alert_emails' => [],
        ]);
        Business::create(['id' => 'biz-1', 'name' => 'Fiscal Shop', 'fiscalisation_enabled' => true, 'tin' => '1234567890']);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'test-device'], $key, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 180, ['digest_alg' => 'sha256']), $cert);
        openssl_pkey_export($key, $pem);

        $this->device = ZimraDevice::create([
            'business_id' => 'biz-1',
            'tin' => '1234567890',
            'device_id' => '321',
            'device_serial_no' => 'SN7',
            'activation_key' => 'ACTIVATION-SECRET',
            'is_active' => true,
            'status' => 'active',
            'certificate_data' => $cert,
            'private_key_data' => $pem,
        ]);

        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), 'GetConfig') => Http::response(['certificateValidTill' => now()->addDays(200)->format('Y-m-d\TH:i:s')]),
                str_contains($request->url(), 'GetStatus') => Http::response(['fiscalDayStatus' => 'FiscalDayClosed', 'lastFiscalDayNo' => 5]),
                str_contains($request->url(), 'OpenDay') => $this->openIsRefused
                    ? Http::response(['title' => 'Fiscal day already open'], 422)
                    : Http::response(['fiscalDayNo' => 6, 'operationID' => 'op-9']),
                str_contains($request->url(), 'Ping') => $this->pingIsDown
                    ? Http::response('down', 500)
                    : Http::response(['reportingFrequency' => 5, 'operationID' => 'op-1']),
                default => Http::response([], 404),
            };
        });
    }

    public function test_a_failed_ping_is_logged_and_marks_the_failed_contact(): void
    {
        $this->pingIsDown = true;

        $this->artisan('zimra:ping')->assertFailed();

        $event = ZimraDeviceEvent::sole();
        $this->assertSame('ping', $event->kind);
        $this->assertFalse($event->success);
        $this->assertSame(500, $event->http_status);
        $this->assertSame('scheduler', $event->source);
        $this->assertNotNull($event->error);
        $device = $this->device->fresh();
        $this->assertNotNull($device->last_contact_failed_at);
        $this->assertNull($device->last_contact_ok_at);
        $this->assertNotNull($device->last_contact_error);
    }

    public function test_a_successful_ping_marks_the_good_contact(): void
    {
        $this->artisan('zimra:ping')->assertSuccessful();

        $event = ZimraDeviceEvent::sole();
        $this->assertTrue($event->success);
        $this->assertStringContainsString('reportingFrequency=5', (string) $event->summary);
        $this->assertNotNull($this->device->fresh()->last_contact_ok_at);
    }

    public function test_a_refused_open_is_logged_with_its_error(): void
    {
        $this->openIsRefused = true;

        $result = app(ZimraClient::class)->openFiscalDay('321', $this->device);

        $this->assertFalse($result['success']);
        $open = ZimraDeviceEvent::where('kind', 'open_day')->sole();
        $this->assertFalse($open->success);
        $this->assertSame(422, $open->http_status);
        $this->assertStringContainsString('already open', (string) $open->error);
        // The config refresh before it is logged too.
        $this->assertTrue(ZimraDeviceEvent::where('kind', 'config')->sole()->success);
    }

    public function test_an_opened_day_is_logged_with_its_number(): void
    {
        app(ZimraClient::class)->openFiscalDay('321', $this->device);

        $open = ZimraDeviceEvent::where('kind', 'open_day')->sole();
        $this->assertTrue($open->success);
        $this->assertSame(6, $open->fiscal_day_no);
    }

    public function test_a_blocked_call_is_logged_but_says_nothing_about_the_connection(): void
    {
        config(['zimra.fiscalisation.allowed_environments' => ['production']]);

        app(ZimraClient::class)->ping('321', $this->device);

        $event = ZimraDeviceEvent::sole();
        $this->assertTrue($event->guard_blocked);
        $this->assertFalse($event->success);
        $device = $this->device->fresh();
        $this->assertNull($device->last_contact_ok_at);
        $this->assertNull($device->last_contact_failed_at);
        Http::assertNothingSent();
    }

    public function test_no_secret_is_ever_stored(): void
    {
        $this->pingIsDown = true;
        $this->artisan('zimra:ping');
        app(ZimraClient::class)->getStatus('321', $this->device);
        // An error message that quotes key material is scrubbed.
        app(ZimraDeviceEventRecorder::class)->record($this->device, 'status', [
            'success' => false,
            'error' => "bad key\n".$this->device->private_key_data,
        ]);

        $dump = ZimraDeviceEvent::all()->toJson().json_encode($this->device->fresh()->only(['last_contact_error']));
        $this->assertStringNotContainsString('BEGIN', $dump);
        $this->assertStringNotContainsString('ACTIVATION-SECRET', $dump);
        $this->assertStringContainsString('[redacted]', $dump);
    }

    public function test_a_broken_log_never_breaks_the_call(): void
    {
        Schema::drop('zimra_device_events');

        $result = app(ZimraClient::class)->getStatus('321', $this->device);

        $this->assertTrue($result['success']);
        $this->assertSame('FiscalDayClosed', $result['data']['fiscalDayStatus']);
    }

    public function test_old_events_are_pruned(): void
    {
        app(ZimraClient::class)->getStatus('321', $this->device);
        ZimraDeviceEvent::query()->update(['occurred_at' => now()->subDays(91)]);
        app(ZimraClient::class)->getStatus('321', $this->device);

        $this->artisan('model:prune', ['--model' => [ZimraDeviceEvent::class]]);

        $this->assertSame(1, ZimraDeviceEvent::count());
    }
}
