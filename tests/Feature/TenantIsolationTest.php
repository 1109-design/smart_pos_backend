<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeBusinessWithUser(string $tenantId, string $pin, ?string $email = null): Tenant
    {
        $tenant = Tenant::create([
            'id' => $tenantId,
            'business_name' => 'Biz '.$tenantId,
            'owner_email' => $tenantId.'@example.com',
            'tier' => 'starter',
            'is_active' => true,
        ]);

        // business_id is passed explicitly here because tenancy is not
        // initialized inside the test; in the app it is back-filled by the
        // User model's creating hook whenever a tenant context is active.
        User::create([
            'business_id' => $tenantId,
            'name' => 'User '.$tenantId,
            'email' => $email ?? $tenantId.'-user@example.com',
            'password' => Hash::make('secret'),
            'pin_hash' => Hash::make($pin),
            'is_active' => true,
        ]);

        return $tenant;
    }

    /**
     * A unique client IP per request so the shared device-auth rate limiter
     * can't leak attempt counts between assertions or other tests.
     */
    private function loginFromFreshDevice(string $businessCode, string $pin): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.random_int(1, 254)])
            ->postJson('/api/v1/auth/device', [
                'device_identifier' => (string) str()->uuid(),
                'device_name' => 'Till',
                'pin' => $pin,
                'business_code' => $businessCode,
            ]);
    }

    public function test_device_login_cannot_match_a_pin_from_another_business(): void
    {
        $this->makeBusinessWithUser('biz-alpha', '1111');
        $this->makeBusinessWithUser('biz-beta', '2222');

        // Alpha's till presents Beta's PIN — this must NOT authenticate as the
        // Beta user (the cross-tenant bypass this scoping closes).
        $this->loginFromFreshDevice('biz-alpha', '2222')->assertStatus(401);

        // Alpha's own PIN still works.
        $this->loginFromFreshDevice('biz-alpha', '1111')->assertOk();
    }

    public function test_device_setup_will_not_pair_to_a_user_outside_the_named_business(): void
    {
        $this->makeBusinessWithUser('biz-one', '1111', email: 'owner@one.com');
        $this->makeBusinessWithUser('biz-two', '2222', email: 'owner@two.com');

        // Correct business + its own email/PIN → ok.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->postJson('/api/v1/auth/setup', [
                'device_identifier' => (string) str()->uuid(),
                'device_name' => 'Till',
                'email' => 'owner@one.com',
                'pin' => '1111',
                'business_code' => 'biz-one',
            ])->assertOk();

        // owner@two.com belongs to biz-two — a biz-one device must not be able
        // to pair to that identity even with the correct PIN for that user.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])
            ->postJson('/api/v1/auth/setup', [
                'device_identifier' => (string) str()->uuid(),
                'device_name' => 'Till',
                'email' => 'owner@two.com',
                'pin' => '2222',
                'business_code' => 'biz-one',
            ])->assertStatus(401);
    }
}
