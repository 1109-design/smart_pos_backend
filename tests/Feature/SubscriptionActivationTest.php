<?php

namespace Tests\Feature;

use App\Models\ActivationCode;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class SubscriptionActivationTest extends TestCase
{
    use RefreshDatabase;

    private function makeDeviceToken(string $tenantId, array $tenantAttrs = [], bool $revoked = false): string
    {
        Tenant::create(array_merge([
            'id' => $tenantId,
            'business_name' => 'Test Business '.$tenantId,
            'owner_email' => $tenantId.'@example.com',
        ], $tenantAttrs));

        $user = User::factory()->create();

        $plain = $user->createToken('test-device')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => str()->uuid(),
            'token_id' => $tokenId,
            'is_revoked' => $revoked,
        ]);

        return $plain;
    }

    public function test_redeem_upgrades_tenant_tier_and_marks_code_used(): void
    {
        $tenantId = 'tenant-redeem-1';
        $token = $this->makeDeviceToken($tenantId, ['tier' => 'starter']);

        $expires = now()->addMonth();
        $code = ActivationCode::create([
            'tenant_id' => $tenantId,
            'code' => 'ABCD1234EFGH5678',
            'tier' => 'pro',
            'expires_at' => $expires,
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/subscription/redeem', ['activation_code' => $code->code]);

        $response->assertOk();
        $response->assertJson(['tier' => 'pro']);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'tier' => 'pro',
        ]);

        $this->assertDatabaseHas('activation_codes', [
            'id' => $code->id,
            'status' => 'used',
        ]);

        $this->assertDatabaseHas('subscription_history', [
            'tenant_id' => $tenantId,
            'tier' => 'pro',
            'event_type' => 'ACTIVATION_REDEEMED',
            'previous_tier' => 'starter',
        ]);
    }

    public function test_activate_revives_revoked_device_and_expired_subscription(): void
    {
        $tenantId = 'tenant-activate-1';
        $this->makeDeviceToken($tenantId, [
            'tier' => 'pro',
            'subscription_valid_until' => now()->subDay(),
        ], revoked: true);

        $device = Device::where('tenant_id', $tenantId)->first();
        $device->update(['token_id' => null]);

        // Activation resolves the business owner inside tenant context, which
        // scopes users by business_id in the single-database architecture.
        User::factory()->create(['business_id' => $tenantId, 'is_active' => true]);

        $expires = now()->addMonth();
        $code = ActivationCode::create([
            'tenant_id' => $tenantId,
            'code' => 'REVIVE1234ABCD56',
            'tier' => 'pro',
            'expires_at' => $expires,
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/v1/auth/device/activate', [
            'device_identifier' => $device->device_identifier,
            'device_name' => 'Revived Device',
            'activation_code' => $code->code,
            'business_code' => $tenantId,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'user', 'business', 'server_time']);
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('pro', $response->json('business.tier'));
        $this->assertNotNull($response->json('business.subscription_valid_until'));

        // The device is un-revoked and the subscription revived.
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'is_revoked' => false,
        ]);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'tier' => 'pro',
        ]);
        $this->assertTrue(
            Tenant::find($tenantId)->subscription_valid_until->isFuture(),
        );
        $this->assertDatabaseHas('activation_codes', [
            'id' => $code->id,
            'status' => 'used',
        ]);
        $this->assertDatabaseHas('subscription_history', [
            'tenant_id' => $tenantId,
            'event_type' => 'ACTIVATION_REDEEMED',
        ]);
    }

    public function test_activate_rejects_invalid_code(): void
    {
        $tenantId = 'tenant-activate-2';
        $this->makeDeviceToken($tenantId, ['tier' => 'starter']);

        $response = $this->postJson('/api/v1/auth/device/activate', [
            'device_identifier' => str()->uuid()->toString(),
            'device_name' => 'Some Device',
            'activation_code' => 'DOESNOTEXIST0000',
            'business_code' => $tenantId,
        ]);

        $response->assertStatus(400);
        $this->assertDatabaseHas('tenants', ['id' => $tenantId, 'tier' => 'starter']);
    }

    public function test_redeem_rejects_code_belonging_to_another_tenant(): void
    {
        $token = $this->makeDeviceToken('tenant-redeem-2', ['tier' => 'starter']);

        // A code that exists, but for a different business entirely.
        Tenant::create([
            'id' => 'tenant-other',
            'business_name' => 'Other Business',
            'owner_email' => 'other@example.com',
        ]);
        $foreignCode = ActivationCode::create([
            'tenant_id' => 'tenant-other',
            'code' => 'ZZZZ9999YYYY8888',
            'tier' => 'ultimate',
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/subscription/redeem', ['activation_code' => $foreignCode->code]);

        $response->assertStatus(400);
        $this->assertDatabaseHas('tenants', ['id' => 'tenant-redeem-2', 'tier' => 'starter']);
    }

    public function test_redeem_rejects_expired_code(): void
    {
        $tenantId = 'tenant-redeem-3';
        $token = $this->makeDeviceToken($tenantId, ['tier' => 'starter']);

        $code = ActivationCode::create([
            'tenant_id' => $tenantId,
            'code' => 'EXPI1111RED22222',
            'tier' => 'pro',
            'expires_at' => now()->subDay(),
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/subscription/redeem', ['activation_code' => $code->code]);

        $response->assertStatus(400);
    }

    public function test_redeem_rejects_already_used_code(): void
    {
        $tenantId = 'tenant-redeem-4';
        $token = $this->makeDeviceToken($tenantId, ['tier' => 'starter']);

        $code = ActivationCode::create([
            'tenant_id' => $tenantId,
            'code' => 'USED1111CODE2222',
            'tier' => 'pro',
            'status' => 'used',
            'used_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/subscription/redeem', ['activation_code' => $code->code]);

        $response->assertStatus(400);
    }

    public function test_redeem_is_case_insensitive_on_code(): void
    {
        $tenantId = 'tenant-redeem-5';
        $token = $this->makeDeviceToken($tenantId, ['tier' => 'starter']);

        $code = ActivationCode::create([
            'tenant_id' => $tenantId,
            'code' => 'LOWER1111CASE222',
            'tier' => 'ultimate',
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/subscription/redeem', ['activation_code' => strtolower($code->code)]);

        $response->assertOk();
        $response->assertJson(['tier' => 'ultimate']);
    }

    public function test_status_returns_current_tier_and_expiry(): void
    {
        $tenantId = 'tenant-status-1';
        $validUntil = now()->addDays(15);
        $token = $this->makeDeviceToken($tenantId, [
            'tier' => 'ultimate',
            'subscription_valid_until' => $validUntil,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/subscription/status');

        $response->assertOk();
        $response->assertJson([
            'tier' => 'ultimate',
            'is_active' => true,
        ]);
        $this->assertNotNull($response->json('server_time'));
    }

    public function test_status_heartbeat_extends_token_expiry(): void
    {
        $tenantId = 'tenant-sliding-1';
        $token = $this->makeDeviceToken($tenantId, ['tier' => 'pro',
            'subscription_valid_until' => now()->addMonth()]);

        // Simulate a token nearing its hard cutoff.
        $tokenId = (int) explode('|', $token)[0];
        PersonalAccessToken::findOrFail($tokenId)
            ->forceFill(['expires_at' => now()->addDays(3)])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/subscription/status')
            ->assertOk();

        $expiresAt = PersonalAccessToken::findOrFail($tokenId)->expires_at;
        $this->assertTrue(
            $expiresAt->greaterThan(now()->addDays(80)),
            'Heartbeat should slide token expiry back out to ~90 days.',
        );
    }

    public function test_revoked_device_is_blocked_from_status_and_redeem(): void
    {
        $tenantId = 'tenant-revoked-1';
        $token = $this->makeDeviceToken($tenantId, ['tier' => 'starter'], revoked: true);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/subscription/status')
            ->assertStatus(403);

        $code = ActivationCode::create([
            'tenant_id' => $tenantId,
            'code' => 'REVO1111KED22222',
            'tier' => 'pro',
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/subscription/redeem', ['activation_code' => $code->code])
            ->assertStatus(403);
    }

    public function test_revoked_device_cannot_relogin_with_pin(): void
    {
        $tenantId = 'tenant-relogin-1';
        Tenant::create([
            'id' => $tenantId,
            'tier' => 'starter',
            'business_name' => 'Test Business '.$tenantId,
            'owner_email' => $tenantId.'@example.com',
        ]);

        $deviceId = (string) str()->uuid();
        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Old Device',
            'device_identifier' => $deviceId,
            'token_id' => null,
            'is_revoked' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/device', [
            'device_identifier' => $deviceId,
            'device_name' => 'Old Device',
            'pin' => '1234',
            'business_code' => $tenantId,
        ]);

        $response->assertStatus(403);
    }

    public function test_device_auth_login_is_rate_limited(): void
    {
        // A dedicated IP so this test's attempt count can't be polluted by
        // other tests hitting the same throttled endpoint in this process.
        $ip = '203.0.113.'.random_int(1, 254);

        $payload = [
            'device_identifier' => (string) str()->uuid(),
            'device_name' => 'Brute Force Device',
            'pin' => '0000',
            'business_code' => 'does-not-exist',
        ];

        for ($i = 0; $i < 6; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v1/auth/device', $payload)
                ->assertStatus(404);
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/device', $payload)
            ->assertStatus(429);
    }
}
