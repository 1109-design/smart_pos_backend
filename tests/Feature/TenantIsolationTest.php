<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TillCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeBusinessWithUser(string $tenantId, string $password, ?string $email = null): Tenant
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
        $user = User::create([
            'business_id' => $tenantId,
            'name' => 'User '.$tenantId,
            'email' => $email ?? $tenantId.'-user@example.com',
            'password' => Hash::make('Back-Office-1'),
            'pin_hash' => Hash::make('1234'),
            'is_active' => true,
        ]);
        app(TillCredentials::class)->setPassword($user, $password, mustChange: false);

        return $tenant;
    }

    /**
     * A unique client IP per request so the shared device-auth rate limiter
     * can't leak attempt counts between assertions or other tests.
     */
    private function postFromFreshDevice(string $uri, array $body): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.random_int(1, 254)])
            ->postJson($uri, array_merge([
                'device_identifier' => (string) str()->uuid(),
                'device_name' => 'Till',
            ], $body));
    }

    public function test_device_login_cannot_use_a_password_from_another_business(): void
    {
        $this->makeBusinessWithUser('biz-alpha', 'Alpha-Pass-1', email: 'a@alpha.com');
        $this->makeBusinessWithUser('biz-beta', 'Beta-Pass-2', email: 'b@beta.com');

        // Alpha's till presents Beta's credentials — this must NOT
        // authenticate as the Beta user (the cross-tenant bypass this
        // scoping closes).
        $this->postFromFreshDevice('/api/v1/auth/device', [
            'email' => 'b@beta.com', 'password' => 'Beta-Pass-2', 'business_code' => 'biz-alpha',
        ])->assertStatus(401);

        // Alpha's own credentials still work.
        $this->postFromFreshDevice('/api/v1/auth/device', [
            'email' => 'a@alpha.com', 'password' => 'Alpha-Pass-1', 'business_code' => 'biz-alpha',
        ])->assertOk();
    }

    public function test_device_setup_will_not_pair_to_a_user_outside_the_named_business(): void
    {
        $this->makeBusinessWithUser('biz-one', 'One-Pass-1', email: 'owner@one.com');
        $this->makeBusinessWithUser('biz-two', 'Two-Pass-2', email: 'owner@two.com');

        // Correct business + its own email/password → ok.
        $this->postFromFreshDevice('/api/v1/auth/setup', [
            'email' => 'owner@one.com', 'password' => 'One-Pass-1', 'business_code' => 'biz-one',
        ])->assertOk()->assertJsonPath('till_password_set', true);

        // owner@two.com belongs to biz-two — a biz-one device must not be able
        // to pair to that identity even with the correct password for that user.
        $this->postFromFreshDevice('/api/v1/auth/setup', [
            'email' => 'owner@two.com', 'password' => 'Two-Pass-2', 'business_code' => 'biz-one',
        ])->assertStatus(401);
    }

    public function test_device_setup_no_longer_accepts_a_pin(): void
    {
        $this->makeBusinessWithUser('biz-pin', 'Real-Pass-1', email: 'owner@pin.com');

        $this->postFromFreshDevice('/api/v1/auth/setup', [
            'email' => 'owner@pin.com', 'pin' => '1234', 'business_code' => 'biz-pin',
        ])->assertUnprocessable();
        $this->postFromFreshDevice('/api/v1/auth/setup', [
            'email' => 'owner@pin.com', 'password' => '1234', 'business_code' => 'biz-pin',
        ])->assertStatus(401);
    }

    public function test_once_a_till_password_is_set_the_back_office_password_no_longer_pairs(): void
    {
        $this->makeBusinessWithUser('biz-bo', 'Till-Pass-1', email: 'owner@bo.com');

        $this->postFromFreshDevice('/api/v1/auth/setup', [
            'email' => 'owner@bo.com', 'password' => 'Back-Office-1', 'business_code' => 'biz-bo',
        ])->assertStatus(401);
    }

    public function test_without_a_till_password_the_back_office_password_pairs(): void
    {
        Tenant::create([
            'id' => 'biz-new', 'business_name' => 'New', 'owner_email' => 'n@new.com',
            'tier' => 'starter', 'is_active' => true,
        ]);
        User::create([
            'business_id' => 'biz-new', 'name' => 'New Owner', 'email' => 'owner@new.com',
            'password' => Hash::make('Back-Office-1'), 'is_active' => true,
        ]);

        $this->postFromFreshDevice('/api/v1/auth/setup', [
            'email' => 'owner@new.com', 'password' => 'Back-Office-1', 'business_code' => 'biz-new',
        ])->assertOk()->assertJsonPath('till_password_set', false);
    }
}
