<?php

namespace Tests\Feature\Accounting;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeAccountRoleMappingsTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId, string $role = 'business_owner'): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);
        Business::firstOrCreate(['id' => $tenantId], ['name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId,
            'email' => $tenantId.'-user@example.com', 'is_active' => true,
        ]);

        session(['backoffice' => [
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'user_name' => $user->name,
            'user_email' => $user->email, 'role' => $role, 'business_name' => $tenantId,
            'currency_code' => 'USD',
        ]]);

        return $user;
    }

    public function test_index_lists_every_role_with_its_current_account(): void
    {
        $tenantId = 'tenant-map-1';
        $this->actingBackOfficeSession($tenantId);

        $response = $this->get('/office/account-mappings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('roles', 8));
    }

    public function test_manager_cannot_access_account_mappings(): void
    {
        $tenantId = 'tenant-map-2';
        $this->actingBackOfficeSession($tenantId, 'manager');

        $this->get('/office/account-mappings')->assertForbidden();
    }

    public function test_updating_a_mapping_persists_the_new_account(): void
    {
        $tenantId = 'tenant-map-3';
        $this->actingBackOfficeSession($tenantId);
        $account = GlAccount::where('business_id', $tenantId)->where('code', '1005')->firstOrFail();

        $this->post('/office/account-mappings', [
            'role' => 'default_bank',
            'gl_account_id' => $account->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('account_role_mappings', [
            'business_id' => $tenantId,
            'role' => 'default_bank',
            'gl_account_id' => $account->id,
        ]);
    }

    public function test_a_business_cannot_map_a_role_to_another_businesss_account(): void
    {
        $tenantId = 'tenant-map-4';
        $otherTenantId = 'tenant-map-5';
        $this->actingBackOfficeSession($otherTenantId);
        $foreignAccount = GlAccount::where('business_id', $otherTenantId)->where('code', '1005')->firstOrFail();

        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/account-mappings', [
            'role' => 'default_bank',
            'gl_account_id' => $foreignAccount->id,
        ])->assertNotFound();
    }
}
