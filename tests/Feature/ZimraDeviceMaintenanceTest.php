<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Zimra\ZimraDevice;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZimraDeviceMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private ZimraDevice $device;

    private string $originalCertificate;

    private string $originalKey;

    private string $validTill;

    private string $fiscalDayStatus = 'FiscalDayClosed';

    private bool $pingIsDown = false;

    private bool $configIsRefused = false;

    /** @var (callable(string): mixed)|null */
    private $issueResponse = null;

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

        [$this->originalCertificate, $this->originalKey] = $this->makeCertificatePair();
        $this->validTill = now('Africa/Harare')->addDays(200)->format('Y-m-d\TH:i:s');

        $this->device = ZimraDevice::create([
            'business_id' => 'biz-1',
            'tin' => '1234567890',
            'device_id' => '321',
            'device_serial_no' => 'SN7',
            'is_active' => true,
            'status' => 'active',
            'certificate_data' => $this->originalCertificate,
            'private_key_data' => $this->originalKey,
        ]);

        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), 'GetConfig') => $this->configIsRefused
                    ? Http::response(['title' => 'Unauthorized'], 401)
                    : Http::response(['certificateValidTill' => $this->validTill]),
                str_contains($request->url(), 'GetStatus') => Http::response(['fiscalDayStatus' => $this->fiscalDayStatus, 'lastFiscalDayNo' => 5]),
                str_contains($request->url(), 'Ping') => $this->pingIsDown
                    ? Http::response('down', 503)
                    : Http::response(['reportingFrequency' => 5, 'operationID' => 'op-1']),
                str_contains($request->url(), 'IssueCertificate') => $this->issueResponse
                    ? ($this->issueResponse)($request['certificateRequest'])
                    : Http::response(['certificate' => $this->signRequest($request['certificateRequest']), 'operationID' => 'op-2']),
                default => Http::response([], 404),
            };
        });
    }

    /**
     * @return array{0: string, 1: string} [certificatePem, privateKeyPem]
     */
    private function makeCertificatePair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'test-device'], $key, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 180, ['digest_alg' => 'sha256']), $certificatePem);
        openssl_pkey_export($key, $privateKeyPem);

        return [$certificatePem, $privateKeyPem];
    }

    private function signRequest(string $csrPem): string
    {
        $caKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_x509_export(openssl_csr_sign($csrPem, null, $caKey, 180, ['digest_alg' => 'sha256']), $certificatePem);

        return $certificatePem;
    }

    private function expireIn(int $days): void
    {
        $this->validTill = now('Africa/Harare')->addDays($days)->format('Y-m-d\TH:i:s');
    }

    private function calls(string $endpoint): int
    {
        return count(Http::recorded(fn (Request $request) => str_contains($request->url(), $endpoint)));
    }

    public function test_a_ping_reports_every_active_device_and_no_other(): void
    {
        ZimraDevice::create([
            'business_id' => 'biz-1', 'tin' => '1234567890', 'device_id' => '999', 'is_active' => false, 'status' => 'inactive',
            'certificate_data' => $this->originalCertificate, 'private_key_data' => $this->originalKey,
        ]);

        $this->artisan('zimra:ping')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/Device/v1/321/Ping'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/999/'));
        $this->assertNotNull($this->device->fresh()->last_sync_at);
    }

    public function test_a_blocked_environment_never_pings_zimra(): void
    {
        config(['zimra.fiscalisation.enabled' => false]);

        $this->artisan('zimra:ping')->assertFailed();

        $this->assertSame(0, $this->calls('Ping'));
        $this->assertNull($this->device->fresh()->last_sync_at);
    }

    public function test_a_failed_ping_is_reported_and_not_recorded_as_a_success(): void
    {
        $this->pingIsDown = true;

        $this->artisan('zimra:ping')->assertFailed();

        $this->assertNull($this->device->fresh()->last_sync_at);
    }

    public function test_ping_is_scheduled_every_five_minutes_and_renewal_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertSame('*/5 * * * *', $events->first(fn ($event) => str_contains($event->command, 'zimra:ping'))->expression);
        $this->assertSame('15 0 * * *', $events->first(fn ($event) => str_contains($event->command, 'zimra:renew-certificates'))->expression);
    }

    public function test_get_config_records_when_the_certificate_expires_as_utc(): void
    {
        $this->validTill = '2027-05-05T14:43:15';

        $this->artisan('zimra:renew-certificates')->assertSuccessful();

        $this->assertTrue(Carbon::parse('2027-05-05 14:43:15', 'UTC')->equalTo($this->device->fresh()->certificate_expires_at));
    }

    public function test_a_certificate_with_plenty_of_time_left_is_not_renewed(): void
    {
        $this->artisan('zimra:renew-certificates')->assertSuccessful();

        $this->assertSame(0, $this->calls('IssueCertificate'));
        $this->assertSame($this->originalKey, $this->device->fresh()->private_key_data);
    }

    public function test_a_certificate_close_to_expiring_is_replaced_with_a_matching_pair(): void
    {
        $this->expireIn(20);

        $this->artisan('zimra:renew-certificates')->assertSuccessful();

        $device = $this->device->fresh();
        $this->assertNotSame($this->originalCertificate, $device->certificate_data);
        $this->assertNotSame($this->originalKey, $device->private_key_data);
        $this->assertTrue(openssl_x509_check_private_key($device->certificate_data, $device->private_key_data));
        $this->assertTrue($device->certificate_expires_at->gt(now()->addDays(170)));

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/Device/v1/321/IssueCertificate')) {
                return false;
            }

            return openssl_csr_get_subject($request['certificateRequest'])['CN'] === 'ZIMRA-SN7-0000000321';
        });
    }

    public function test_force_renews_a_certificate_that_is_not_due(): void
    {
        $this->artisan('zimra:renew-certificates', ['device_id' => '321', '--force' => true])->assertSuccessful();

        $this->assertSame(1, $this->calls('IssueCertificate'));
        $this->assertNotSame($this->originalKey, $this->device->fresh()->private_key_data);
    }

    public function test_a_certificate_is_never_replaced_while_the_fiscal_day_is_open(): void
    {
        $this->expireIn(20);
        $this->fiscalDayStatus = 'FiscalDayOpened';

        $this->artisan('zimra:renew-certificates', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, $this->calls('IssueCertificate'));
        $this->assertSame($this->originalKey, $this->device->fresh()->private_key_data);
    }

    public function test_an_expired_certificate_is_flagged_for_registration_instead_of_renewed(): void
    {
        $this->expireIn(-1);

        $this->artisan('zimra:renew-certificates')->assertFailed();

        $this->assertSame(0, $this->calls('IssueCertificate'));
    }

    public function test_a_refused_renewal_keeps_the_working_certificate_and_key(): void
    {
        $this->expireIn(20);
        $this->issueResponse = fn () => Http::response(['title' => 'Refused'], 422);

        $this->artisan('zimra:renew-certificates')->assertFailed();

        $device = $this->device->fresh();
        $this->assertSame($this->originalCertificate, $device->certificate_data);
        $this->assertSame($this->originalKey, $device->private_key_data);
    }

    public function test_a_returned_certificate_for_a_different_key_is_not_stored(): void
    {
        $this->expireIn(20);
        $this->issueResponse = fn () => Http::response(['certificate' => $this->makeCertificatePair()[0]]);

        $this->artisan('zimra:renew-certificates')->assertFailed();

        $device = $this->device->fresh();
        $this->assertSame($this->originalCertificate, $device->certificate_data);
        $this->assertSame($this->originalKey, $device->private_key_data);
    }

    public function test_a_revoked_certificate_is_flagged_for_registration_at_once(): void
    {
        $this->configIsRefused = true;

        $this->artisan('zimra:renew-certificates')->assertFailed();

        $this->assertSame(0, $this->calls('IssueCertificate'));
        $this->assertSame($this->originalKey, $this->device->fresh()->private_key_data);
    }

    public function test_a_blocked_environment_never_renews(): void
    {
        $this->expireIn(20);
        config(['zimra.fiscalisation.enabled' => false]);

        $this->artisan('zimra:renew-certificates')->assertSuccessful();

        $this->assertSame(0, $this->calls('GetConfig'));
        $this->assertSame(0, $this->calls('IssueCertificate'));
    }
}
