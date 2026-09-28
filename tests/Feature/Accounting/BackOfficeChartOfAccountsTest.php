<?php

namespace Tests\Feature\Accounting;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\GlAccount;
use App\Models\AccountRoleMapping;
use App\Models\Business;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeChartOfAccountsTest extends TestCase
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

    public function test_index_lists_every_seeded_category_with_its_accounts(): void
    {
        $tenantId = 'tenant-coa-1';
        $this->actingBackOfficeSession($tenantId);

        $response = $this->get('/office/chart-of-accounts');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('categories', 6));
    }

    public function test_manager_cannot_access_chart_of_accounts(): void
    {
        $tenantId = 'tenant-coa-2';
        $this->actingBackOfficeSession($tenantId, 'manager');

        $this->get('/office/chart-of-accounts')->assertForbidden();
    }

    public function test_creating_a_category_persists_it(): void
    {
        $tenantId = 'tenant-coa-3';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/chart-of-accounts/categories', [
            'name' => 'Other Income',
            'is_debit_normal' => false,
            'statement_type' => 'income_statement',
        ])->assertRedirect();

        $this->assertDatabaseHas('account_categories', [
            'business_id' => $tenantId, 'name' => 'Other Income', 'is_system' => false,
        ]);
    }

    public function test_creating_an_account_auto_assigns_its_code(): void
    {
        $tenantId = 'tenant-coa-4';
        $this->actingBackOfficeSession($tenantId);
        $assets = AccountCategory::where('business_id', $tenantId)->where('code', 1000)->firstOrFail();

        $this->post('/office/chart-of-accounts/accounts', [
            'name' => 'Petty Cash Tin',
            'account_category_id' => $assets->id,
            'account_sub_category_id' => null,
            'control_type' => null,
            'allow_direct_posting' => true,
            'must_be_positive' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('gl_accounts', [
            'business_id' => $tenantId, 'name' => 'Petty Cash Tin', 'code' => '1900',
        ]);
    }

    public function test_updating_an_account_cannot_smuggle_in_a_code_change(): void
    {
        $tenantId = 'tenant-coa-5';
        $this->actingBackOfficeSession($tenantId);
        $account = GlAccount::where('business_id', $tenantId)->where('code', '6090')->firstOrFail();
        $assets = AccountCategory::where('business_id', $tenantId)->where('code', 1000)->firstOrFail();

        // 'code' isn't in the validated field list at all — even sending it
        // must have no effect.
        $this->patch("/office/chart-of-accounts/accounts/{$account->id}", [
            'name' => 'Renamed',
            'code' => '9999',
            'account_category_id' => $assets->id,
            'account_sub_category_id' => null,
            'control_type' => null,
            'allow_direct_posting' => true,
            'must_be_positive' => false,
        ])->assertRedirect();

        $this->assertSame('6090', $account->fresh()->code);
        $this->assertSame('Renamed', $account->fresh()->name);
    }

    public function test_deactivating_a_mapped_account_shows_the_blocking_error(): void
    {
        $tenantId = 'tenant-coa-6';
        $this->actingBackOfficeSession($tenantId);
        $account = GlAccount::where('business_id', $tenantId)->where('code', '4000')->firstOrFail();
        AccountRoleMapping::create(['business_id' => $tenantId, 'role' => 'default_cash', 'gl_account_id' => $account->id]);

        $response = $this->post("/office/chart-of-accounts/accounts/{$account->id}/deactivate");

        $response->assertSessionHasErrors('account');
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_a_business_cannot_deactivate_another_businesss_account(): void
    {
        $tenantId = 'tenant-coa-7';
        $otherTenantId = 'tenant-coa-8';
        $this->actingBackOfficeSession($otherTenantId);
        $foreignAccount = GlAccount::where('business_id', $otherTenantId)->where('code', '6090')->firstOrFail();

        $this->actingBackOfficeSession($tenantId);

        $this->post("/office/chart-of-accounts/accounts/{$foreignAccount->id}/deactivate")->assertNotFound();
    }
}
