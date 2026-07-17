<?php

namespace Tests\Feature;

use App\Models\ActivationCode;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivationCodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
    }

    private function expiredBusiness(): Tenant
    {
        return Tenant::create([
            'id' => 'expired-biz',
            'business_name' => 'Lapsed Store',
            'owner_email' => 'lapsed@x.com',
            'tier' => 'pro',
            'subscription_valid_until' => now()->subDays(5),
            'is_active' => true,
        ]);
    }

    public function test_generating_a_code_for_an_expired_business_yields_a_future_expiry(): void
    {
        $this->actingAsAdmin();
        $tenant = $this->expiredBusiness();

        // No body — the same call the "Generate" button makes today.
        $this->post("/businesses/{$tenant->id}/activation-codes")
            ->assertRedirect();

        $code = ActivationCode::where('tenant_id', $tenant->id)->firstOrFail();

        // The footgun fix: a code minted while the business is expired must
        // still carry a future grant, otherwise it redeems straight back into
        // a locked state.
        $this->assertTrue($code->expires_at->isFuture());
        $this->assertTrue($code->isValid());
        $this->assertSame('pro', $code->tier);
    }

    public function test_admin_can_specify_tier_and_days(): void
    {
        $this->actingAsAdmin();
        $tenant = $this->expiredBusiness();

        $this->post("/businesses/{$tenant->id}/activation-codes", [
            'tier' => 'ultimate',
            'days' => 7,
        ])->assertRedirect();

        $code = ActivationCode::where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertSame('ultimate', $code->tier);
        $this->assertEqualsWithDelta(
            now()->addDays(7)->timestamp,
            $code->expires_at->timestamp,
            60,
        );
    }

    public function test_generated_code_actually_reactivates_the_expired_business(): void
    {
        $this->actingAsAdmin();
        $tenant = $this->expiredBusiness();
        $this->assertFalse($tenant->isSubscriptionActive());

        // Admin generates a code (owner has paid).
        $this->post("/businesses/{$tenant->id}/activation-codes", ['tier' => 'pro', 'days' => 30]);
        $code = ActivationCode::where('tenant_id', $tenant->id)->firstOrFail();

        // A paired device redeems it and comes back to life.
        $user = User::factory()->create(['business_id' => $tenant->id]);
        $plain = $user->createToken('till')->plainTextToken;
        Device::create([
            'tenant_id' => $tenant->id,
            'name' => 'Till',
            'device_identifier' => (string) str()->uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/subscription/redeem', ['activation_code' => $code->code])
            ->assertOk()
            ->assertJson(['tier' => 'pro', 'is_active' => true]);

        $this->assertTrue($tenant->fresh()->isSubscriptionActive());
    }
}
