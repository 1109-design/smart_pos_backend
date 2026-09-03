<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\BackOfficeRolePermission;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BackOfficePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeRolesTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId, string $role = 'business_owner'): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'email' => $tenantId.'-user@example.com',
            'is_active' => true,
        ]);

        session([
            'backoffice' => [
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'role' => $role,
                'business_name' => $tenantId,
                'currency_code' => 'USD',
            ],
        ]);

        return $user;
    }

    public function test_owner_can_view_the_roles_page(): void
    {
        $tenantId = 'tenant-roles-0';
        $this->actingBackOfficeSession($tenantId);

        $this->get('/office/roles')->assertOk();
    }

    public function test_owner_can_create_a_custom_role_and_grant_it_a_permission(): void
    {
        $tenantId = 'tenant-roles-1';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/roles', ['name' => 'warehouse_clerk'])->assertRedirect();

        $this->assertDatabaseHas('backoffice_role_permissions', [
            'business_id' => $tenantId,
            'role' => 'warehouse_clerk',
        ]);

        $role = BackOfficeRolePermission::where('business_id', $tenantId)->where('role', 'warehouse_clerk')->first();
        $this->assertSame([], $role->permissions_json);

        $this->put('/office/roles/warehouse_clerk', ['permissions' => [BackOfficePermission::MANAGE_SUPPLIERS]])
            ->assertRedirect();

        $role->refresh();
        $this->assertSame([BackOfficePermission::MANAGE_SUPPLIERS], $role->permissions_json);
    }

    public function test_a_custom_role_with_a_granted_permission_can_use_it_but_nothing_else(): void
    {
        $tenantId = 'tenant-roles-2';
        $this->actingBackOfficeSession($tenantId);

        BackOfficeRolePermission::create([
            'business_id' => $tenantId,
            'role' => 'auditor',
            'permissions_json' => [BackOfficePermission::MANAGE_SUPPLIERS],
        ]);

        // Assign a user to the custom role.
        $this->post('/office/users', [
            'name' => 'Audit User',
            'email' => 'audit@example.com',
            'role' => 'auditor',
            'pin' => '4444',
        ])->assertRedirect();

        $auditor = User::where('email', 'audit@example.com')->first();
        session(['backoffice' => array_merge(session('backoffice'), [
            'user_id' => $auditor->id,
            'role' => 'auditor',
        ])]);

        // Granted permission: suppliers page loads.
        $this->get('/office/suppliers')->assertOk();

        // Not granted: users management stays forbidden.
        $this->get('/office/users')->assertForbidden();
    }

    public function test_business_owner_role_cannot_be_restricted(): void
    {
        $tenantId = 'tenant-roles-3';
        $this->actingBackOfficeSession($tenantId);

        $this->put('/office/roles/business_owner', ['permissions' => []])->assertForbidden();
    }

    public function test_manager_cannot_manage_roles(): void
    {
        $tenantId = 'tenant-roles-4';
        $this->actingBackOfficeSession($tenantId, 'manager');

        $this->get('/office/roles')->assertForbidden();
        $this->post('/office/roles', ['name' => 'sneaky'])->assertForbidden();
    }

    /**
     * Regression: update() used to call updateOrCreate() directly, silently
     * minting a brand-new role for any string in the URL — bypassing every
     * validation rule store() enforces on a role name (format, reserved
     * names, per-business uniqueness).
     */
    public function test_cannot_create_a_new_role_through_the_update_endpoint(): void
    {
        $tenantId = 'tenant-roles-update-bypass';
        $this->actingBackOfficeSession($tenantId);

        $this->put('/office/roles/totally-new-role', ['permissions' => []])
            ->assertNotFound();

        $this->assertDatabaseMissing('backoffice_role_permissions', [
            'business_id' => $tenantId,
            'role' => 'totally-new-role',
        ]);
    }

    public function test_can_update_permissions_for_an_existing_custom_role(): void
    {
        $tenantId = 'tenant-roles-update-existing';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/roles', ['name' => 'auditor'])->assertRedirect();

        $this->put('/office/roles/auditor', ['permissions' => [BackOfficePermission::MANAGE_USERS]])
            ->assertRedirect();

        $role = BackOfficeRolePermission::where('business_id', $tenantId)->where('role', 'auditor')->first();
        $this->assertSame([BackOfficePermission::MANAGE_USERS], $role->permissions_json);
    }

    public function test_can_update_permissions_for_a_builtin_role_that_has_never_been_customized(): void
    {
        $tenantId = 'tenant-roles-update-builtin';
        $this->actingBackOfficeSession($tenantId);

        $this->put('/office/roles/manager', ['permissions' => [BackOfficePermission::MANAGE_SUPPLIERS]])
            ->assertRedirect();

        $role = BackOfficeRolePermission::where('business_id', $tenantId)->where('role', 'manager')->first();
        $this->assertSame([BackOfficePermission::MANAGE_SUPPLIERS], $role->permissions_json);
    }

    public function test_cannot_create_a_role_with_a_builtin_name(): void
    {
        $tenantId = 'tenant-roles-5';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/roles', ['name' => 'manager'])->assertSessionHasErrors('name');
    }

    public function test_manager_permissions_default_to_current_behavior_until_customized(): void
    {
        $tenantId = 'tenant-roles-6';
        $this->actingBackOfficeSession($tenantId, 'manager');

        // No BackOfficeRolePermission row exists yet for 'manager' — the
        // authorizer must fall back to today's hardcoded default (managers
        // could always manage suppliers) rather than denying everything.
        $this->get('/office/suppliers')->assertOk();
    }
}
