<?php

namespace Tests\Feature;

use App\Models\ActivationCode;
use App\Models\Device;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function register(array $overrides = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(1, 254)])
            ->postJson('/api/v1/auth/register', array_merge([
                'device_identifier' => (string) str()->uuid(),
                'device_name' => 'Front Till',
                'business_name' => 'Nyasha Groceries',
                'owner_name' => 'Nyasha M',
                'owner_email' => 'nyasha@shop.co.zw',
                'pin' => '1234',
                'password' => 'trial-password-123',
                'country' => 'ZW',
                'currency_code' => 'USD',
            ], $overrides));
    }

    public function test_self_registration_creates_trial_business_and_pairs_device(): void
    {
        $response = $this->register();

        $response->assertCreated();
        $response->assertJsonPath('business.tier', config('trial.tier'));
        $response->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'email', 'role', 'permissions'],
            'business' => ['id', 'name', 'tier', 'currency_code', 'pairing_code', 'subscription_valid_until'],
        ]);

        $tenantId = $response->json('business.id');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'owner_email' => 'nyasha@shop.co.zw',
            'tier' => config('trial.tier'),
        ]);
        $this->assertDatabaseHas('devices', ['tenant_id' => $tenantId, 'name' => 'Front Till']);
        $this->assertDatabaseHas('subscription_history', [
            'tenant_id' => $tenantId,
            'event_type' => 'TRIAL_STARTED',
        ]);

        // The trial is time-limited, not "free forever".
        $tenant = Tenant::find($tenantId);
        $this->assertTrue($tenant->subscription_valid_until->isFuture());
        $this->assertTrue($tenant->isSubscriptionActive());

        // The returned token immediately works against an authenticated endpoint.
        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/v1/subscription/status')
            ->assertOk()
            ->assertJson(['is_active' => true]);
    }

    public function test_owner_becomes_business_owner_and_is_isolated_from_other_businesses(): void
    {
        $this->register(['owner_email' => 'a@shop.co.zw', 'pin' => '1111']);
        $this->register([
            'owner_email' => 'b@shop.co.zw',
            'business_name' => 'Other Shop',
            'pin' => '2222',
        ]);

        // Owner of the second business cannot log a device into the first
        // business using their own PIN.
        $first = Tenant::where('owner_email', 'a@shop.co.zw')->first();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
            ->postJson('/api/v1/auth/device', [
                'device_identifier' => (string) str()->uuid(),
                'device_name' => 'Rogue',
                'pin' => '2222',
                'business_code' => $first->pairing_code,
            ])->assertStatus(401);
    }

    public function test_trial_lapses_then_an_activation_code_unlocks_the_device(): void
    {
        // 1. Owner self-registers → 1-day trial, device paired.
        $token = $this->register()->json('token');
        $tenant = Tenant::first();

        // 2. The trial lapses.
        $this->travelTo(now()->addDays((int) config('trial.duration_days') + 1));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/subscription/status')
            ->assertOk()
            ->assertJson(['is_active' => false]); // locked

        // 3. Owner pays; admin issues an activation code carrying a paid period.
        $code = ActivationCode::create([
            'tenant_id' => $tenant->id,
            'code' => 'PAID1234PAID5678',
            'tier' => 'pro',
            'expires_at' => now()->addDays(30),
            'status' => 'pending',
        ]);

        // 4. Device redeems it → auto-unlocks (no re-login).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/subscription/redeem', ['activation_code' => $code->code])
            ->assertOk()
            ->assertJson(['tier' => 'pro', 'is_active' => true]);

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'tier' => 'pro']);
    }

    public function test_registration_is_blocked_for_a_duplicate_email(): void
    {
        $this->register(['owner_email' => 'dup@shop.co.zw'])->assertCreated();

        $this->register(['owner_email' => 'dup@shop.co.zw'])
            ->assertStatus(409);

        $this->assertSame(1, Tenant::where('owner_email', 'dup@shop.co.zw')->count());
    }

    public function test_registration_is_blocked_for_an_already_registered_device(): void
    {
        $deviceId = (string) str()->uuid();

        $this->register(['device_identifier' => $deviceId, 'owner_email' => 'first@shop.co.zw'])
            ->assertCreated();

        $this->register(['device_identifier' => $deviceId, 'owner_email' => 'second@shop.co.zw'])
            ->assertStatus(409);

        $this->assertSame(1, Device::where('device_identifier', $deviceId)->count());
    }
}
