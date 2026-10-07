<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceDbSnapshot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** On-demand device database copies — see DeviceDbSnapshotController. */
class DeviceDbSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const READER = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['sync.diagnostics_reader_devices' => [self::READER]]);
        Storage::fake('local');
    }

    private function deviceToken(string $tenantId, string $name, ?string $identifier = null): string
    {
        if (! Tenant::query()->whereKey($tenantId)->exists()) {
            Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        }
        $user = User::factory()->create(['email' => $tenantId.'-'.Str::random(6).'@example.com']);
        $plain = $user->createToken('snap-test')->plainTextToken;

        Device::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'device_identifier' => $identifier ?? (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return $plain;
    }

    /** Uploads [$bytes] in chunks of [$size] the way the app does. */
    private function upload(string $token, int $id, string $bytes, int $size = 4): void
    {
        $chunks = str_split($bytes, $size);
        foreach ($chunks as $i => $chunk) {
            $this->withToken($token)->postJson("/api/v1/sync/diagnostics/db-snapshots/$id/chunks", [
                'index' => $i,
                'total' => count($chunks),
                'data' => base64_encode($chunk),
            ])->assertOk();
        }
    }

    public function test_reader_requests_device_uploads_reader_downloads(): void
    {
        $reader = $this->deviceToken('biz-1', 'Dev box', self::READER);
        $till = $this->deviceToken('biz-1', 'SmartPOS 2CE9BA');

        $id = $this->withToken($reader)
            ->postJson('/api/v1/sync/diagnostics/db-snapshots', ['device' => '2CE9BA'])
            ->assertCreated()
            ->assertJson(['status' => 'requested', 'device_name' => 'SmartPOS 2CE9BA'])
            ->json('id');

        // Asking again while open reuses the request.
        $this->withToken($reader)
            ->postJson('/api/v1/sync/diagnostics/db-snapshots', ['device' => '2CE9BA'])
            ->assertJson(['id' => $id]);

        // The till learns about it from its next diagnostics report.
        $this->withToken($till)->postJson('/api/v1/sync/diagnostics', ['issues' => []])
            ->assertOk()->assertJson(['db_snapshot_request' => ['id' => $id]]);

        $bytes = random_bytes(37);
        $this->upload($till, $id, $bytes);
        $this->withToken($till)->postJson("/api/v1/sync/diagnostics/db-snapshots/$id/complete", [
            'sha256' => hash('sha256', $bytes),
            'app_version' => '1.0.36+1',
            'schema_version' => 110,
        ])->assertOk()->assertJson(['status' => 'ready']);

        // Answered: no longer offered to the till.
        $this->withToken($till)->postJson('/api/v1/sync/diagnostics', ['issues' => []])
            ->assertJson(['db_snapshot_request' => null]);

        $this->withToken($reader)->getJson('/api/v1/sync/diagnostics/db-snapshots')
            ->assertOk()
            ->assertJsonPath('snapshots.0.status', 'ready')
            ->assertJsonPath('snapshots.0.size_bytes', 37);

        $response = $this->withToken($reader)->get("/api/v1/sync/diagnostics/db-snapshots/$id/download");
        $response->assertOk();
        $this->assertSame($bytes, $response->streamedContent());
    }

    public function test_only_the_reader_can_request_list_or_download(): void
    {
        $this->deviceToken('biz-1', 'Dev box', self::READER);
        $till = $this->deviceToken('biz-1', 'SmartPOS 2CE9BA');

        $this->withToken($till)->postJson('/api/v1/sync/diagnostics/db-snapshots', ['device' => 'Dev'])
            ->assertForbidden();
        $this->withToken($till)->getJson('/api/v1/sync/diagnostics/db-snapshots')->assertForbidden();
        $this->withToken($till)->get('/api/v1/sync/diagnostics/db-snapshots/1/download')->assertForbidden();
    }

    public function test_only_the_asked_device_can_upload(): void
    {
        $reader = $this->deviceToken('biz-1', 'Dev box', self::READER);
        $this->deviceToken('biz-1', 'SmartPOS 2CE9BA');
        $other = $this->deviceToken('biz-1', 'SmartPOS 03CE66');

        $id = $this->withToken($reader)
            ->postJson('/api/v1/sync/diagnostics/db-snapshots', ['device' => '2CE9BA'])
            ->json('id');

        $this->withToken($other)->postJson("/api/v1/sync/diagnostics/db-snapshots/$id/chunks", [
            'index' => 0, 'total' => 1, 'data' => base64_encode('x'),
        ])->assertNotFound();
    }

    public function test_bad_checksum_is_refused_and_can_be_retried(): void
    {
        $reader = $this->deviceToken('biz-1', 'Dev box', self::READER);
        $till = $this->deviceToken('biz-1', 'SmartPOS 2CE9BA');
        $id = $this->withToken($reader)
            ->postJson('/api/v1/sync/diagnostics/db-snapshots', ['device' => '2CE9BA'])
            ->json('id');

        $this->upload($till, $id, 'hello world');
        $this->withToken($till)->postJson("/api/v1/sync/diagnostics/db-snapshots/$id/complete", [
            'sha256' => str_repeat('0', 64),
        ])->assertStatus(422);
        $this->assertSame('uploading', DeviceDbSnapshot::find($id)->status);

        $this->upload($till, $id, 'hello world');
        $this->withToken($till)->postJson("/api/v1/sync/diagnostics/db-snapshots/$id/complete", [
            'sha256' => hash('sha256', 'hello world'),
        ])->assertJson(['status' => 'ready']);
    }

    public function test_device_can_report_that_it_could_not_take_a_copy(): void
    {
        $reader = $this->deviceToken('biz-1', 'Dev box', self::READER);
        $till = $this->deviceToken('biz-1', 'SmartPOS 2CE9BA');
        $id = $this->withToken($reader)
            ->postJson('/api/v1/sync/diagnostics/db-snapshots', ['device' => '2CE9BA'])
            ->json('id');

        $this->withToken($till)->postJson("/api/v1/sync/diagnostics/db-snapshots/$id/complete", [
            'error' => 'disk full',
        ])->assertJson(['status' => 'failed']);
        $this->assertSame('disk full', DeviceDbSnapshot::find($id)->error);
    }

    public function test_ambiguous_device_name_is_rejected(): void
    {
        $reader = $this->deviceToken('biz-1', 'Dev box', self::READER);
        $this->deviceToken('biz-1', 'SmartPOS 2CE9BA');
        $this->deviceToken('biz-1', 'SmartPOS 03CE66');

        $this->withToken($reader)
            ->postJson('/api/v1/sync/diagnostics/db-snapshots', ['device' => 'SmartPOS'])
            ->assertStatus(422)
            ->assertJsonCount(2, 'matches');
    }
}
