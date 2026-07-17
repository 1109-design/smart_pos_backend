<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Guards the admin (back-office) user-management surface against cross-tenant
 * access. Isolation here rides on the same User global scope that protects
 * device auth, so these tests are the tripwire that fails loudly if that scope
 * is ever removed or bypassed on this surface.
 */
class UserManagementIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // A central super-admin operates the back-office without a tenant
        // context (business_id is null), so the scope is inert for them.
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
    }

    private function business(string $id): Tenant
    {
        return Tenant::create([
            'id' => $id,
            'business_name' => 'Biz '.$id,
            'owner_email' => $id.'@example.com',
            'tier' => 'starter',
            'is_active' => true,
        ]);
    }

    private function userIn(string $businessId, string $name): User
    {
        return User::create([
            'business_id' => $businessId,
            'name' => $name,
            'email' => strtolower($name).'@'.$businessId.'.com',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);
    }

    public function test_index_lists_only_the_managed_business_users(): void
    {
        $this->business('biz-a');
        $this->business('biz-b');
        $this->userIn('biz-a', 'Alice');
        $this->userIn('biz-b', 'Bob');

        // Requesting as Inertia returns the page as JSON, sidestepping the
        // Vite-rendered HTML shell (which isn't built in the test env).
        $response = $this->get('/businesses/biz-a/users', ['X-Inertia' => 'true']);

        $response->assertOk();
        $response->assertJsonPath('component', 'Users/Index');
        $response->assertJsonCount(1, 'props.users');
        $response->assertJsonPath('props.users.0.name', 'Alice');
    }

    public function test_admin_cannot_update_a_user_from_another_business(): void
    {
        $this->business('biz-a');
        $this->business('biz-b');
        $bob = $this->userIn('biz-b', 'Bob');

        // Managing biz-a, attempt to edit biz-b's user by id.
        $this->put("/businesses/biz-a/users/{$bob->id}", [
            'name' => 'Hacked',
            'email' => 'hacked@example.com',
            'role' => 'cashier',
            'is_active' => true,
        ])->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $bob->id, 'name' => 'Bob']);
    }

    public function test_admin_cannot_delete_a_user_from_another_business(): void
    {
        $this->business('biz-a');
        $this->business('biz-b');
        $bob = $this->userIn('biz-b', 'Bob');

        $this->delete("/businesses/biz-a/users/{$bob->id}")->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $bob->id]);
    }

    public function test_created_user_is_scoped_to_the_managed_business(): void
    {
        $this->business('biz-a');

        $this->post('/businesses/biz-a/users', [
            'name' => 'Cashier One',
            'email' => 'cashier1@example.com',
            'password' => 'password123',
            'role' => 'cashier',
            'pin' => '4444',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'cashier1@example.com',
            'business_id' => 'biz-a',
        ]);
    }
}
