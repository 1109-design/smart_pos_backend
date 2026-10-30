<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceSyncHealth;
use App\Models\DeviceSyncIssue;
use App\Models\SyncConflict;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The app's Devices screen: every device of the business, nobody else's. */
class DeviceOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $tenantId, string $name): array
    {
        Tenant::firstOrCreate(['id' => $tenantId], [
            'business_name' => $tenantId,
            'owner_email' => $tenantId.'@example.com',
        ]);
        $user = User::factory()->create();
        $plain = $user->createToken($name)->plainTextToken;
        $device = Device::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return [$device, $plain];
    }

    public function test_lists_every_device_of_the_business_with_its_health(): void
    {
        [$till, $token] = $this->device('biz-a', 'Till 1');
        [$office] = $this->device('biz-a', 'Office PC');
        $this->device('biz-b', 'Someone else');

        DeviceSyncHealth::create([
            'device_id' => $office->id,
            'business_id' => 'biz-a',
            'app_version' => '1.0.50+1',
            'platform' => 'windows "Windows 10 Pro"',
            'current_user' => 'Brian (cashier)',
            'snapshot' => ['tables' => [
                'sales' => ['pending' => 2, 'failing' => 1, 'conflicts' => 0],
                'products' => ['pending' => 3, 'gave_up' => 1],
            ]],
        ]);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/devices/overview')
            ->assertOk();

        $devices = collect($res->json('devices'))->keyBy('name');
        $this->assertEqualsCanonicalizing(['Till 1', 'Office PC'], $devices->keys()->all());
        $this->assertTrue($devices['Till 1']['is_this_device']);
        $this->assertSame('1.0.50+1', $devices['Office PC']['app_version']);
        $this->assertSame('Brian (cashier)', $devices['Office PC']['current_user']);
        $this->assertSame(5, $devices['Office PC']['queue']['pending']);
        $this->assertSame(1, $devices['Office PC']['queue']['failing']);
        $this->assertSame(1, $devices['Office PC']['queue']['gave_up']);
    }

    public function test_only_live_conflicts_and_recent_problems_count(): void
    {
        [$till, $token] = $this->device('biz-a', 'Till 1');
        foreach (['pending', 'resolved'] as $status) {
            SyncConflict::create([
                'business_id' => 'biz-a',
                'table_name' => 'po_audit_logs',
                'record_uuid' => 'po-'.$status,
                'device_id' => $till->id,
                'reason' => 'Newer version exists on server',
                'conflict_type' => 'version_conflict',
                'status' => $status,
            ]);
        }
        $issue = fn (string $category, $seen) => DeviceSyncIssue::create([
            'business_id' => 'biz-a',
            'device_id' => $till->id,
            'fingerprint' => $category.$seen,
            'source' => 'cloud_pull',
            'category' => $category,
            'message' => 'x',
            'occurrences' => 1,
            'first_seen_at' => $seen,
            'last_seen_at' => $seen,
        ]);
        $issue('pull_bad_record', now());
        $issue('pull_network_error', now()->subDays(10));
        $issue('conflict_auto_accepted', now());

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/devices/overview')
            ->assertOk();

        $this->assertSame(1, $res->json('devices.0.queue.conflicts'));
        $this->assertSame(1, $res->json('devices.0.open_issues'));
    }

    public function test_a_pull_marks_the_device_as_seen(): void
    {
        [$till, $token] = $this->device('biz-a', 'Till 1');
        $this->assertNull($till->fresh()->last_seen_at);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull')
            ->assertOk();

        $this->assertNotNull($till->fresh()->last_seen_at);
    }
}
