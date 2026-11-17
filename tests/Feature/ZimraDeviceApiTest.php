<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Device;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraDeviceEvent;
use App\Models\Zimra\ZimraFiscalDay;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Finance → Fiscal Devices as the tills call it: scoped to the paired
 * business, gated by viewFiscalDevices, never leaking device secrets, and
 * "Check now" only reading from ZIMRA.
 */
class ZimraDeviceApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-fiscal-1';

    private string $token;

    private User $owner;

    private ZimraDevice $device;

    private string $zimraStatus = 'FiscalDayOpened';

    private bool $zimraDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['zimra.failure_alert_emails' => []]);
        Tenant::create(['id' => $this->tenantId, 'business_name' => 'Fiscal Co', 'owner_email' => 'fiscal@example.com']);
        Business::create(['id' => $this->tenantId, 'name' => 'Fiscal Co', 'currency_code' => 'USD', 'fiscalisation_enabled' => true]);
        [$this->owner, $this->token] = $this->userWithToken('business_owner');

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'test-device'], $key, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 180, ['digest_alg' => 'sha256']), $cert);
        openssl_pkey_export($key, $pem);

        $this->device = ZimraDevice::create([
            'business_id' => $this->tenantId,
            'tin' => '1234567890',
            'device_id' => '38660',
            'device_serial_no' => 'SN-1',
            'activation_key' => 'ACTIVATION-SECRET',
            'is_active' => true,
            'status' => 'active',
            'certificate_data' => $cert,
            'private_key_data' => $pem,
            'fdms_config' => ['taxPayerName' => 'TAXPAYER-SECRET'],
            'certificate_expires_at' => now()->addDays(200),
            'fiscal_day_max_hours' => 24,
        ]);
        $this->device->forceFill(['last_contact_ok_at' => now()->subMinutes(2)])->save();
        ZimraFiscalDay::create([
            'business_id' => $this->tenantId, 'device_id' => '38660', 'fiscal_day_no' => 8,
            'status' => 'closed', 'opened_at' => now()->subDays(2), 'closed_at' => now()->subDay(),
        ]);
        ZimraFiscalDay::create([
            'business_id' => $this->tenantId, 'device_id' => '38660', 'fiscal_day_no' => 9,
            'status' => 'open', 'opened_at' => now()->subHours(3),
            'close_attempts' => [['at' => now()->toIso8601String(), 'success' => false]],
        ]);

        Http::fake(function (Request $request) {
            return match (true) {
                $this->zimraDown => Http::response('down', 503),
                str_contains($request->url(), 'GetStatus') => Http::response(['fiscalDayStatus' => $this->zimraStatus, 'fiscalDayNo' => 9, 'lastReceiptGlobalNo' => 41]),
                default => Http::response([], 404),
            };
        });
        RateLimiter::clear('zimra-check:'.$this->device->id);
    }

    /** @return array{0: User, 1: string} */
    private function userWithToken(string $role, ?string $tenant = null): array
    {
        $tenant ??= $this->tenantId;
        Role::findOrCreate($role);
        $user = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenant, 'email' => Str::random(8).'@example.com', 'is_active' => true]);
        $user->assignRole($role);
        $plain = $user->createToken('till')->plainTextToken;
        Device::create([
            'tenant_id' => $tenant,
            'name' => 'Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return [$user, $plain];
    }

    private function api(string $method, string $uri, ?User $as = null, ?string $token = null)
    {
        $userId = ($as ?? $this->owner)->id;
        $request = $this->withHeader('Authorization', 'Bearer '.($token ?? $this->token));

        return $method === 'GET'
            ? $request->getJson($uri.(str_contains($uri, '?') ? '&' : '?').'user_id='.$userId)
            : $request->postJson($uri, ['user_id' => $userId]);
    }

    public function test_lists_the_business_devices_with_a_verdict_and_no_secrets(): void
    {
        $response = $this->api('GET', '/api/v1/zimra/devices')->assertOk();

        $response->assertJsonPath('devices.0.device_id', '38660')
            ->assertJsonPath('devices.0.current_fiscal_day.fiscal_day_no', 9)
            ->assertJsonPath('devices.0.current_fiscal_day.status', 'open')
            ->assertJsonPath('devices.0.health.verdict', 'ok')
            ->assertJsonCount(1, 'devices');
        $body = $response->getContent();
        foreach (['BEGIN', 'ACTIVATION-SECRET', 'TAXPAYER-SECRET', 'private_key', 'certificate_data', 'activation_key', 'fdms_config'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_another_business_sees_none_of_it(): void
    {
        Tenant::create(['id' => 'tenant-other', 'business_name' => 'Other', 'owner_email' => 'o@example.com']);
        Business::create(['id' => 'tenant-other', 'name' => 'Other', 'currency_code' => 'USD']);
        [$otherOwner, $otherToken] = $this->userWithToken('business_owner', 'tenant-other');

        $this->api('GET', '/api/v1/zimra/devices', $otherOwner, $otherToken)->assertOk()->assertJsonCount(0, 'devices');
        $this->api('GET', '/api/v1/zimra/devices/38660/fiscal-days', $otherOwner, $otherToken)->assertNotFound();
        $this->api('POST', '/api/v1/zimra/devices/38660/check', $otherOwner, $otherToken)->assertNotFound();
    }

    public function test_the_dashboard_status_is_for_everyone_and_holds_no_secrets(): void
    {
        [$cashier, $cashierToken] = $this->userWithToken('cashier');

        $response = $this->api('GET', '/api/v1/zimra/status', $cashier, $cashierToken)->assertOk();

        $response->assertJsonCount(1, 'devices')
            ->assertJsonPath('devices.0.device_id', '38660')
            ->assertJsonPath('devices.0.health.verdict', 'ok')
            ->assertJsonPath('devices.0.current_fiscal_day.fiscal_day_no', 9)
            ->assertJsonPath('devices.0.current_fiscal_day.status', 'open')
            ->assertJsonPath('devices.0.pending_sales', 0);
        $body = $response->getContent();
        foreach (['BEGIN', 'ACTIVATION-SECRET', 'TAXPAYER-SECRET', 'private_key', 'certificate_data', 'activation_key', 'fdms_config'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        // Scoped to the paired business, and an inactive device isn't shown.
        Tenant::create(['id' => 'tenant-other', 'business_name' => 'Other', 'owner_email' => 'o2@example.com']);
        [$other, $otherToken] = $this->userWithToken('cashier', 'tenant-other');
        $this->api('GET', '/api/v1/zimra/status', $other, $otherToken)->assertOk()->assertJsonCount(0, 'devices');
        $this->device->update(['is_active' => false]);
        $this->api('GET', '/api/v1/zimra/status', $cashier, $cashierToken)->assertOk()->assertJsonCount(0, 'devices');
    }

    public function test_needs_the_view_fiscal_devices_permission(): void
    {
        [$cashier] = $this->userWithToken('cashier');
        $this->api('GET', '/api/v1/zimra/devices', $cashier)->assertForbidden();

        // A person from another business can't be borrowed.
        [$stranger] = $this->userWithToken('business_owner', 'tenant-x');
        $this->api('GET', '/api/v1/zimra/devices', $stranger)->assertForbidden();

        // No user at all.
        $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/v1/zimra/devices')->assertForbidden();
    }

    public function test_default_roles_and_synced_roles_decide(): void
    {
        [$accountant] = $this->userWithToken('accountant');
        $this->api('GET', '/api/v1/zimra/devices', $accountant)->assertOk();

        // Once the role's row has synced it rules — here without the key.
        RolePermission::create(['business_id' => $this->tenantId, 'role' => 'accountant', 'permissions_json' => json_encode(['viewDailyReport'])]);
        $this->api('GET', '/api/v1/zimra/devices', $accountant)->assertForbidden();

        [$cashier] = $this->userWithToken('cashier');
        RolePermission::create(['business_id' => $this->tenantId, 'role' => 'cashier', 'permissions_json' => json_encode(['viewFiscalDevices'])]);
        $this->api('GET', '/api/v1/zimra/devices', $cashier)->assertOk();
    }

    public function test_fiscal_days_newest_first_with_attempts(): void
    {
        $this->api('GET', '/api/v1/zimra/devices/38660/fiscal-days')->assertOk()
            ->assertJsonPath('data.0.fiscal_day_no', 9)
            ->assertJsonPath('data.0.close_attempts', 1)
            ->assertJsonPath('data.1.fiscal_day_no', 8)
            ->assertJsonPath('data.1.status', 'closed')
            ->assertJsonMissingPath('data.0.close_payload')
            ->assertJsonPath('total', 2);
    }

    public function test_events_filter_to_failures(): void
    {
        foreach ([true, false, true] as $i => $ok) {
            ZimraDeviceEvent::create([
                'business_id' => $this->tenantId, 'device_id' => '38660', 'kind' => 'ping',
                'success' => $ok, 'error' => $ok ? null : 'HTTP 503: down', 'occurred_at' => now()->subMinutes(10 - $i),
            ]);
        }

        $this->api('GET', '/api/v1/zimra/devices/38660/events')->assertOk()->assertJsonPath('total', 3);
        $this->api('GET', '/api/v1/zimra/devices/38660/events?failures=1')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.error', 'HTTP 503: down');
    }

    public function test_check_reads_zimra_logs_it_and_changes_nothing(): void
    {
        // A check is a contact with ZIMRA (last_contact_* may move); nothing
        // else about the device or its days may change.
        $snapshot = fn () => [
            (array) DB::table('zimra_devices')->where('id', $this->device->id)
                ->first(['status', 'is_active', 'fiscal_day_opened_at', 'certificate_expires_at', 'tax_codes']),
            DB::table('zimra_fiscal_days')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
        $before = $snapshot();

        $this->api('POST', '/api/v1/zimra/devices/38660/check')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('zimra_status.fiscalDayStatus', 'FiscalDayOpened')
            ->assertJsonPath('mismatch', null)
            ->assertJsonPath('device.health.verdict', 'ok');

        $event = ZimraDeviceEvent::sole();
        $this->assertSame('status', $event->kind);
        $this->assertSame('manual', $event->source);
        $this->assertSame((string) $this->owner->id, $event->user_id);
        $this->assertSame($before, $snapshot());
        Http::assertSentCount(1);
    }

    public function test_check_says_when_zimra_and_the_server_disagree(): void
    {
        $this->zimraStatus = 'FiscalDayClosed';

        $this->api('POST', '/api/v1/zimra/devices/38660/check')->assertOk()
            ->assertJsonPath('mismatch', "ZIMRA says fiscal day 9 is closed, but the server's record has fiscal day 9 open.");
    }

    public function test_check_is_limited_and_a_zimra_failure_is_still_logged(): void
    {
        $this->zimraDown = true;

        $this->api('POST', '/api/v1/zimra/devices/38660/check')->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('device.health.verdict', 'warning');
        $this->assertFalse(ZimraDeviceEvent::sole()->success);

        $this->api('POST', '/api/v1/zimra/devices/38660/check')->assertStatus(429);
        $this->assertSame(1, ZimraDeviceEvent::count());
    }
}
