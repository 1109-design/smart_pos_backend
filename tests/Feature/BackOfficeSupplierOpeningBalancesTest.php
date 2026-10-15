<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeSupplierOpeningBalancesTest extends TestCase
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

    private function account(string $businessId, string $code): GlAccount
    {
        return GlAccount::where('business_id', $businessId)->where('code', $code)->firstOrFail();
    }

    public function test_cashier_cannot_record_an_opening_balance(): void
    {
        $tenantId = 'tenant-supplier-ob-3';
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->actingBackOfficeSession($tenantId, 'cashier');

        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies', 'is_active' => true]);

        $this->post("/office/suppliers/{$supplier->id}/opening-balance", ['amount' => 500, 'as_of_date' => '2026-01-01'])
            ->assertForbidden();
    }

    public function test_opening_balance_is_refused_when_accounting_is_not_live(): void
    {
        $tenantId = 'tenant-supplier-ob-5';
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->actingBackOfficeSession($tenantId);

        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies', 'is_active' => true]);

        $this->post("/office/suppliers/{$supplier->id}/opening-balance", ['amount' => 500, 'as_of_date' => '2026-01-01'])
            ->assertSessionHasErrors('amount');
    }
}
