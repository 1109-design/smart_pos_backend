<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceSyncHealth;
use App\Models\DeviceSyncIssue;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Per-device sync diagnostics — see SyncDiagnosticsController. */
class SyncDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private const READER = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['sync.diagnostics_reader_devices' => [self::READER]]);
    }

    private function deviceToken(string $tenantId, string $name, ?string $identifier = null): string
    {
        if (! Tenant::query()->whereKey($tenantId)->exists()) {
            Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        }
        $user = User::factory()->create(['email' => $tenantId.'-'.Str::random(6).'@example.com']);
        $plain = $user->createToken('diag-test')->plainTextToken;

        Device::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'device_identifier' => $identifier ?? (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function issue(array $overrides = []): array
    {
        return array_merge([
            'fingerprint' => 'fp-1',
            'source' => 'cloud_push',
            'category' => 'push_rejected',
            'table' => 'transactions',
            'message' => 'Rejected by server: missing product',
            'record_uuids' => ['a', 'b'],
            'record_uuid_count' => 2,
            'sample_payload' => ['id' => 'a', 'total' => 10],
            'details' => ['http_status' => 200, 'attempts' => 3],
            'stack' => '#0 foo',
            'occurrences' => 3,
            'first_seen_at' => '2026-10-06T10:00:00Z',
            'last_seen_at' => '2026-10-06T10:05:00Z',
            'app_version' => '1.0.33',
        ], $overrides);
    }

    public function test_device_reports_issues_and_health_idempotently(): void
    {
        $token = $this->deviceToken('biz-1', 'Till 2');

        $body = [
            'device' => ['app_version' => '1.0.33', 'device_time' => now()->addSeconds(120)->toIso8601String()],
            'health' => ['tables' => ['transactions' => ['pending' => 4]]],
            'issues' => [$this->issue()],
        ];

        $this->withToken($token)->postJson('/api/v1/sync/diagnostics', $body)
            ->assertOk()->assertJson(['accepted' => ['fp-1']]);
        // Resend (lost response) must not duplicate.
        $this->withToken($token)->postJson('/api/v1/sync/diagnostics', $body)->assertOk();

        $this->assertSame(1, DeviceSyncIssue::count());
        $issue = DeviceSyncIssue::first();
        $this->assertSame('biz-1', $issue->business_id);
        $this->assertSame(3, $issue->occurrences);
        $this->assertSame(['a', 'b'], $issue->record_uuids);

        $health = DeviceSyncHealth::first();
        $this->assertSame(4, $health->snapshot['tables']['transactions']['pending']);
        $this->assertEqualsWithDelta(120, $health->clock_skew_seconds, 5);
    }

    public function test_only_reader_device_can_read_and_it_sees_every_device(): void
    {
        $till = $this->deviceToken('biz-1', 'Till 2');
        $reader = $this->deviceToken('biz-1', 'Dev Machine', self::READER);

        $this->withToken($till)->postJson('/api/v1/sync/diagnostics', ['issues' => [$this->issue()]])->assertOk();

        $this->withToken($till)->getJson('/api/v1/sync/diagnostics')->assertForbidden();

        $this->withToken($reader)->getJson('/api/v1/sync/diagnostics')
            ->assertOk()
            ->assertJsonCount(2, 'devices')
            ->assertJsonPath('issues.0.device_name', 'Till 2')
            ->assertJsonPath('issues.0.sample_payload.total', 10)
            ->assertJsonStructure(['server' => ['pending_conflicts', 'deferred_records', 'changelog_live_uuids_per_table']]);
    }

    public function test_resolved_issue_reopens_when_it_recurs(): void
    {
        $till = $this->deviceToken('biz-1', 'Till 2');
        $reader = $this->deviceToken('biz-1', 'Dev Machine', self::READER);

        $this->withToken($till)->postJson('/api/v1/sync/diagnostics', ['issues' => [$this->issue()]])->assertOk();

        $this->withToken($reader)->postJson('/api/v1/sync/diagnostics/resolve', ['fingerprints' => ['fp-1'], 'note' => 'fixed in 1.0.34'])
            ->assertOk()->assertJson(['resolved' => 1]);
        $this->assertNotNull(DeviceSyncIssue::first()->resolved_at);

        $this->withToken($till)->postJson('/api/v1/sync/diagnostics', ['issues' => [
            $this->issue(['occurrences' => 5, 'last_seen_at' => now()->addMinute()->toIso8601String()]),
        ]])->assertOk();

        $issue = DeviceSyncIssue::first();
        $this->assertNull($issue->resolved_at);
        $this->assertSame(5, $issue->occurrences);
    }
}
