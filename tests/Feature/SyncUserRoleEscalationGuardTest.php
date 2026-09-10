<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SyncProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CRITICAL privilege-escalation guard. Master spec section 49: "Attempt
 * privilege escalation and cross-business/branch access." SyncProcessor::
 * syncUser() calls $user->syncRoles([$payload['role']]) for ANY 'users'
 * upsert that includes a role — with no check at all on who was pushing it.
 * Any authenticated device (a cashier's own till included) could push a
 * 'users' upsert for its own id with role: 'business_owner' and instantly
 * grant itself full owner access to the business — reproduced live against
 * a running dev server (a real cashier account, self-promoted, confirmed via
 * direct DB query) before this fix existed. BackOfficeController's
 * UsersController already gated every role assignment behind
 * authorizeManager() (MANAGE_USERS) — but that's the BackOffice web
 * controller upstream of process(), not process()/syncUser() itself, which
 * is also the generic device-sync entry point and had no gate of its own.
 */
class SyncUserRoleEscalationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingDeviceToken(string $tenantId, User $user): string
    {
        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function pushUserRole(string $token, string $tenantId, string $userId, string $role, array $extra = []): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'users',
                    'uuid' => $userId,
                    'operation' => 'upsert',
                    'payload' => array_merge([
                        'business_id' => $tenantId,
                        'name' => 'Test User',
                        'is_active' => true,
                        'role' => $role,
                    ], $extra),
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_a_cashier_cannot_self_promote_to_business_owner(): void
    {
        $tenantId = 'tenant-escalation-self';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $response = $this->pushUserRole($token, $tenantId, $cashier->id, 'business_owner', [
            'name' => $cashier->name,
            'email' => $cashier->email,
        ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'users: role changes require manage_users permission.',
            $response->json('errors.0.reason'),
        );
        $this->assertTrue($cashier->fresh()->hasRole('cashier'));
        $this->assertFalse($cashier->fresh()->hasRole('business_owner'));
    }

    public function test_a_cashier_cannot_promote_a_different_user_either(): void
    {
        $tenantId = 'tenant-escalation-other';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $victim = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-victim@example.com']);
        $victim->assignRole('cashier');

        $response = $this->pushUserRole($token, $tenantId, $victim->id, 'manager', [
            'name' => $victim->name,
            'email' => $victim->email,
        ]);

        $this->assertCount(0, $response->json('accepted'));
        $this->assertTrue($victim->fresh()->hasRole('cashier'));
    }

    public function test_an_owner_can_legitimately_assign_a_role_to_a_new_employee(): void
    {
        $tenantId = 'tenant-escalation-legit';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $newEmployeeId = (string) Str::uuid();
        $response = $this->pushUserRole($token, $tenantId, $newEmployeeId, 'cashier', [
            'name' => 'New Cashier',
            'email' => 'new-cashier@example.com',
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertTrue(User::find($newEmployeeId)->hasRole('cashier'));
    }

    public function test_a_device_resyncing_its_own_unchanged_role_is_not_blocked(): void
    {
        $tenantId = 'tenant-escalation-noop';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        // Same role as already assigned — a routine resync (e.g. a PIN
        // change re-sending the full record), not an escalation attempt.
        $response = $this->pushUserRole($token, $tenantId, $cashier->id, 'cashier', [
            'name' => $cashier->name,
            'email' => $cashier->email,
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
    }

    public function test_a_trusted_server_side_write_is_not_gated(): void
    {
        $tenantId = 'tenant-escalation-trusted';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $newUserId = (string) Str::uuid();

        // Mirrors how BackOffice's UsersController calls this — it has
        // already authorized the request itself upstream (authorizeManager())
        // before ever reaching the processor, same as every other
        // trusted: true caller in this class.
        app(SyncProcessor::class)->process(
            'users',
            $newUserId,
            'upsert',
            [
                'business_id' => $tenantId,
                'name' => 'BackOffice-created user',
                'is_active' => true,
                'role' => 'manager',
            ],
            trusted: true,
        );

        $this->assertTrue(User::find($newUserId)->hasRole('manager'));
    }

    /**
     * Same escalation class, arguably worse: this redefines what an ENTIRE
     * role can do rather than promoting one account — every user with that
     * role gains it the moment their device pulls the change. Reproduced
     * live: a real cashier's own device granted the 'cashier' role
     * manageUsers/voidTransaction/issueRefund/editCurrencyRates with zero
     * check. BackOffice's own RolesController is even stricter than the
     * 'users' gate above — only the business owner may manage roles at
     * all (see its authorizeOwner()) — mirrored exactly here.
     */
    public function test_a_cashier_cannot_grant_their_own_role_extra_permissions(): void
    {
        $tenantId = 'tenant-role-perm-escalation';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'role_permissions',
                    'uuid' => 'role-perm-'.$tenantId.'-cashier',
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'role' => 'cashier',
                        'permissions_json' => json_encode(['manageUsers', 'voidTransaction']),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'role_permissions: only the business owner can manage roles.',
            $response->json('errors.0.reason'),
        );
        $this->assertDatabaseMissing('role_permissions', ['business_id' => $tenantId, 'role' => 'cashier']);
    }

    public function test_a_manager_cannot_manage_roles_either_only_the_owner_can(): void
    {
        $tenantId = 'tenant-role-perm-manager';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $manager = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-manager@example.com']);
        $manager->assignRole('manager');
        $token = $this->actingDeviceToken($tenantId, $manager);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'role_permissions',
                    'uuid' => 'role-perm-'.$tenantId.'-cashier',
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'role' => 'cashier',
                        'permissions_json' => json_encode(['manageUsers']),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $this->assertCount(0, $response->json('accepted'));
    }

    public function test_the_business_owner_can_legitimately_edit_a_roles_permissions(): void
    {
        $tenantId = 'tenant-role-perm-owner';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'role_permissions',
                    'uuid' => 'role-perm-'.$tenantId.'-cashier',
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'role' => 'cashier',
                        'permissions_json' => json_encode(['makeSale', 'viewInventory']),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertNotNull(
            RolePermission::where('business_id', $tenantId)->where('role', 'cashier')->first(),
        );
    }
}
