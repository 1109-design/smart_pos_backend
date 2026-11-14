<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraFiscalDay;
use App\Models\Zimra\ZimraSale;
use App\Services\Zimra\FiscalDeviceHealth;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The verdict a till shows for a fiscal device: every reason is named, and
 * the worst one decides.
 */
class FiscalDeviceHealthTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-11-14 10:00:00');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function device(array $overrides = []): ZimraDevice
    {
        $device = new ZimraDevice(array_merge([
            'business_id' => 'biz-1',
            'device_id' => '1',
            'is_active' => true,
            'status' => 'active',
            'certificate_expires_at' => $this->now->copy()->addDays(200),
            'fiscal_day_max_hours' => 24,
        ], $overrides));
        $device->forceFill(['last_contact_ok_at' => $overrides['last_contact_ok_at'] ?? $this->now->copy()->subMinutes(3)]);
        foreach (['last_contact_failed_at', 'last_contact_error'] as $k) {
            if (array_key_exists($k, $overrides)) {
                $device->forceFill([$k => $overrides[$k]]);
            }
        }

        return $device;
    }

    private function day(array $attrs): ZimraFiscalDay
    {
        return new ZimraFiscalDay(array_merge(['fiscal_day_no' => 9, 'status' => 'open', 'opened_at' => $this->now->copy()->subHours(2)], $attrs));
    }

    /** @return array{verdict: string, reasons: list<array>} */
    private function assess(ZimraDevice $device, ?ZimraFiscalDay $day = null, array $unresolved = [], int $pending = 0, int $failed = 0): array
    {
        return app(FiscalDeviceHealth::class)->assess($device, $day, collect($unresolved), $pending, $failed, $this->now);
    }

    private function codes(array $health): array
    {
        return array_column($health['reasons'], 'code');
    }

    public function test_a_healthy_device_is_ok_with_no_reasons(): void
    {
        $health = $this->assess($this->device(), $this->day([]));

        $this->assertSame('ok', $health['verdict']);
        $this->assertSame([], $health['reasons']);
    }

    public function test_silence_from_zimra_is_broken_not_ok(): void
    {
        $health = $this->assess($this->device(['last_contact_ok_at' => $this->now->copy()->subMinutes(16)]));

        $this->assertSame('broken', $health['verdict']);
        $this->assertSame(['no_contact'], $this->codes($health));
    }

    public function test_a_device_never_heard_from_is_unknown(): void
    {
        $device = $this->device();
        $device->forceFill(['last_contact_ok_at' => null, 'last_sync_at' => null]);

        $health = $this->assess($device);

        $this->assertSame('unknown', $health['verdict']);
        $this->assertSame(['never_contacted'], $this->codes($health));
    }

    public function test_a_failed_last_call_warns_with_its_error(): void
    {
        $health = $this->assess($this->device([
            'last_contact_failed_at' => $this->now->copy()->subMinute(),
            'last_contact_error' => 'HTTP 503: down',
        ]));

        $this->assertSame('warning', $health['verdict']);
        $this->assertStringContainsString('HTTP 503: down', $health['reasons'][0]['message']);
    }

    public function test_the_fiscal_day_limit(): void
    {
        $this->assertSame(['day_near_limit'], $this->codes($this->assess($this->device(), $this->day(['opened_at' => $this->now->copy()->subHours(23)]))));
        $overdue = $this->assess($this->device(), $this->day(['opened_at' => $this->now->copy()->subHours(25)]));
        $this->assertSame('broken', $overdue['verdict']);
        $this->assertSame(['day_overdue'], $this->codes($overdue));
    }

    public function test_unresolved_closes(): void
    {
        $failed = $this->day(['fiscal_day_no' => 7, 'status' => 'close_failed', 'error_message' => 'CountersMismatch']);
        $manual = $this->day(['fiscal_day_no' => 6, 'status' => 'close_failed', 'closing_error_code' => 'ManualCloseRequired']);
        $waiting = $this->day(['fiscal_day_no' => 8, 'status' => 'close_submitted', 'close_submitted_at' => $this->now->copy()->subMinutes(45)]);
        $recent = $this->day(['fiscal_day_no' => 9, 'status' => 'close_submitted', 'close_submitted_at' => $this->now->copy()->subMinutes(5)]);

        $health = $this->assess($this->device(), null, [$manual, $failed, $waiting, $recent]);

        $this->assertSame('broken', $health['verdict']);
        $this->assertSame(['manual_close_required', 'close_failed', 'close_unconfirmed'], $this->codes($health));
        $this->assertStringContainsString('Public Portal', $health['reasons'][0]['message']);
        $this->assertStringContainsString('CountersMismatch', $health['reasons'][1]['message']);
    }

    public function test_the_certificate(): void
    {
        $this->assertSame(['cert_expiring'], $this->codes($this->assess($this->device(['certificate_expires_at' => $this->now->copy()->addDays(10)]))));
        $this->assertSame(['cert_unknown'], $this->codes($this->assess($this->device(['certificate_expires_at' => null]))));
        $expired = $this->assess($this->device(['certificate_expires_at' => $this->now->copy()->subDay()]));
        $this->assertSame('broken', $expired['verdict']);
        $this->assertSame(['cert_expired'], $this->codes($expired));
    }

    public function test_sales_owed_to_zimra(): void
    {
        $this->assertSame('warning', $this->assess($this->device(), null, [], pending: 2)['verdict']);
        $this->assertSame('broken', $this->assess($this->device(), null, [], failed: 1)['verdict']);
    }

    public function test_device_state(): void
    {
        $this->assertSame(['device_error'], $this->codes($this->assess($this->device(['status' => 'error', 'error_message' => 'Bad cert']))));
        $inactive = $this->assess($this->device(['is_active' => false, 'last_contact_ok_at' => $this->now->copy()->subDays(5)]));
        // Switched off: no contact is expected, so only "inactive" is said.
        $this->assertSame(['inactive'], $this->codes($inactive));
        $this->assertSame('warning', $inactive['verdict']);
    }

    public function test_the_worst_reason_wins(): void
    {
        $health = $this->assess(
            $this->device(['certificate_expires_at' => $this->now->copy()->addDays(10)]),
            $this->day(['opened_at' => $this->now->copy()->subHours(30)]),
            pending: 1,
        );

        $this->assertSame('broken', $health['verdict']);
        $this->assertEqualsCanonicalizing(['day_overdue', 'cert_expiring', 'pending_sales'], $this->codes($health));
    }

    public function test_for_business_counts_only_old_waiting_and_recent_failed_sales(): void
    {
        Business::create(['id' => 'biz-1', 'name' => 'Shop', 'fiscalisation_enabled' => true]);
        $device = $this->device(['tin' => '1234567890']);
        $device->save();
        $sale = fn (string $status, Carbon $at) => ZimraSale::forceCreate([
            'business_id' => 'biz-1', 'device_id' => '1', 'tin' => '1', 'status' => $status,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $sale('pending', $this->now->copy()->subMinutes(5));   // just queued: fine
        $sale('pending', $this->now->copy()->subMinutes(40));  // stuck
        $sale('failed', $this->now->copy()->subDays(2));
        $sale('failed', $this->now->copy()->subDays(60));      // outside the window
        $sale('fiscalised', $this->now->copy()->subMinutes(40));

        $row = app(FiscalDeviceHealth::class)->forBusiness('biz-1', $this->now)->sole();

        $this->assertSame(1, $row['pending_sales']);
        $this->assertSame(1, $row['failed_sales']);
        $this->assertSame('broken', $row['health']['verdict']);
    }
}
