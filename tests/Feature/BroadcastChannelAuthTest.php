<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves the realtime broadcast channels ("business.{id}" and
 * "business.{id}.location.{id}") are scoped exactly like every other sync
 * endpoint: a device can only subscribe to its own business, and location
 * channels require the device's own Device.location_id, not just a shared
 * business_id (see routes/channels.php).
 */
class BroadcastChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The `null` broadcaster used by the default test env is a no-op that
        // skips channel authorization entirely — switch to the real `reverb`
        // (Pusher-protocol) driver so Broadcast::auth() actually invokes the
        // registered channel callbacks. No live Reverb server is needed: the
        // auth handshake is a local HMAC signature, not a network call.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        // routes/channels.php was already `require`d once at application boot
        // against whatever connection `BROADCAST_CONNECTION` (null, in tests)
        // resolved to at the time — that broadcaster instance is cached with
        // no channels registered on it. Re-requiring now that the config
        // points at `reverb` registers the same channel patterns against the
        // connection this test actually exercises.
        require base_path('routes/channels.php');
    }

    private function deviceToken(string $tenantId, ?string $locationId = null): string
    {
        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $plain = $user->createToken('realtime-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'location_id' => $locationId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_device_can_authorize_its_own_business_channel(): void
    {
        $token = $this->deviceToken('tenant-realtime-a');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-business.tenant-realtime-a',
                'socket_id' => '1234.5678',
            ]);

        $response->assertOk();
        $this->assertArrayHasKey('auth', $response->json());
    }

    public function test_device_cannot_authorize_another_businesss_channel(): void
    {
        $token = $this->deviceToken('tenant-realtime-b');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-business.tenant-realtime-other',
                'socket_id' => '1234.5678',
            ]);

        $response->assertForbidden();
    }

    public function test_device_can_authorize_its_own_location_channel(): void
    {
        $tenantId = 'tenant-realtime-c';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Main Shop']);

        $token = $this->deviceToken($tenantId, $location->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-business.{$tenantId}.location.{$location->id}",
                'socket_id' => '1234.5678',
            ]);

        $response->assertOk();
        $this->assertArrayHasKey('auth', $response->json());
    }

    public function test_device_cannot_authorize_a_different_locations_channel_in_the_same_business(): void
    {
        $tenantId = 'tenant-realtime-d';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $ownLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Front Shop']);
        $otherLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse']);

        $token = $this->deviceToken($tenantId, $ownLocation->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-business.{$tenantId}.location.{$otherLocation->id}",
                'socket_id' => '1234.5678',
            ]);

        $response->assertForbidden();
    }

    public function test_device_cannot_authorize_another_businesss_location_channel(): void
    {
        $victimTenant = 'tenant-realtime-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $victimLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $victimTenant, 'name' => 'Victim Shop']);

        $attackerToken = $this->deviceToken('tenant-realtime-attacker');

        $response = $this->withHeader('Authorization', 'Bearer '.$attackerToken)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-business.{$victimTenant}.location.{$victimLocation->id}",
                'socket_id' => '1234.5678',
            ]);

        $response->assertForbidden();
    }
}
