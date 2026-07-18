<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeBusinessUser(): User
    {
        Tenant::create([
            'id' => 'tenant-owner-1',
            'business_name' => 'Owner Business',
            'owner_email' => 'owner@example.com',
        ]);

        return User::factory()->create([
            'business_id' => 'tenant-owner-1',
            'email' => 'owner@example.com',
        ]);
    }

    public function test_platform_admin_can_access_admin_panel(): void
    {
        $admin = User::factory()->create(['business_id' => null]);

        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $this->actingAs($admin)->get('/businesses')->assertOk();
    }

    public function test_business_user_is_redirected_to_back_office_and_logged_out(): void
    {
        $owner = $this->makeBusinessUser();

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertRedirect(route('office.login'));
        $this->assertGuest();
    }

    public function test_business_user_cannot_reach_business_management(): void
    {
        $owner = $this->makeBusinessUser();

        $this->actingAs($owner)
            ->get('/businesses')
            ->assertRedirect(route('office.login'));
    }

    public function test_business_user_cannot_generate_activation_codes(): void
    {
        $owner = $this->makeBusinessUser();

        $this->actingAs($owner)
            ->post('/businesses/tenant-owner-1/activation-codes', [])
            ->assertRedirect(route('office.login'));
    }
}
