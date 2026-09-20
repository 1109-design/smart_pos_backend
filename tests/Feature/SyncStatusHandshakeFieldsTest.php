<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SyncController::status() doubles as the closest thing to a SYNC_HELLO
 * handshake response — this covers the fields added for that (see the
 * startup-sync architecture spec): server_time, schema_version,
 * minimum_supported_app_version. The existing pending_pull/cursors shape
 * is left untouched and isn't re-tested here.
 */
class SyncStatusHandshakeFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_response_includes_handshake_fields(): void
    {
        $tenantId = 'tenant-handshake-1';
        Tenant::firstOrCreate(
            ['id' => $tenantId],
            ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']
        );

        $user = User::factory()->create();
        $plain = $user->createToken('Till 01')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Till 01',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/sync/status');

        $response->assertOk();
        $response->assertJsonStructure([
            'pending_pull',
            'device_id',
            'cursors',
            'server_time',
            'schema_version',
            'minimum_supported_app_version',
        ]);
        $this->assertSame(config('sync.schema_version'), $response->json('schema_version'));
        $this->assertSame(
            config('sync.minimum_supported_app_version'),
            $response->json('minimum_supported_app_version')
        );
        $this->assertNotNull($response->json('server_time'));
    }
}
