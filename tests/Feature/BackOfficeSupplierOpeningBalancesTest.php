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
use Illuminate\Http\UploadedFile;
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

    public function test_owner_can_record_a_supplier_opening_balance(): void
    {
        $tenantId = 'tenant-supplier-ob-1';
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->actingBackOfficeSession($tenantId);

        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies', 'is_active' => true]);

        $this->post("/office/suppliers/{$supplier->id}/opening-balance", [
            'amount' => 500,
            'as_of_date' => '2026-01-01',
            'notes' => 'Balance forward',
        ])->assertRedirect();

        $this->assertSame(500.0, $this->account($tenantId, '2000')->balance());
    }

    public function test_cannot_record_a_second_opening_balance_for_the_same_supplier(): void
    {
        $tenantId = 'tenant-supplier-ob-2';
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->actingBackOfficeSession($tenantId);

        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies', 'is_active' => true]);

        $this->post("/office/suppliers/{$supplier->id}/opening-balance", ['amount' => 500, 'as_of_date' => '2026-01-01'])
            ->assertRedirect();
        $this->post("/office/suppliers/{$supplier->id}/opening-balance", ['amount' => 50, 'as_of_date' => '2026-01-02'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(500.0, $this->account($tenantId, '2000')->balance());
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

    public function test_bulk_csv_import_posts_one_opening_balance_per_row(): void
    {
        $tenantId = 'tenant-supplier-ob-4';
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->actingBackOfficeSession($tenantId);

        $supplierA = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Supplier A', 'is_active' => true]);
        $supplierB = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Supplier B', 'is_active' => true]);

        $csv = "supplier_id,supplier_name,opening_balance,as_of_date,notes\n";
        $csv .= "{$supplierA->id},Supplier A,100,2026-01-01,Carried forward\n";
        $csv .= "{$supplierB->id},Supplier B,,2026-01-01,\n"; // blank amount — skipped
        $csv .= "not-a-real-id,Ghost,200,2026-01-01,\n"; // unknown supplier — skipped

        $file = UploadedFile::fake()->createWithContent('opening_balances.csv', $csv);

        $this->post('/office/suppliers/opening-balances/import', ['file' => $file])->assertRedirect();

        $this->assertSame(100.0, $this->account($tenantId, '2000')->balance());
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
