<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeCustomersTest extends TestCase
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

    private function makeCustomer(string $tenantId, array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Jane Shopper',
            'loyalty_points' => 0,
            'credit_balance' => 0,
            'credit_limit' => 0,
        ], $overrides));
    }

    public function test_index_and_show_scope_to_the_current_tenant(): void
    {
        $tenantId = 'tenant-customers-1';
        $this->actingBackOfficeSession($tenantId);

        $mine = $this->makeCustomer($tenantId);
        $this->makeCustomer('tenant-customers-other');

        $index = $this->get('/office/customers');
        $index->assertOk();
        $index->assertInertia(fn ($page) => $page->has('customers.data', 1));

        $this->get("/office/customers/{$mine->id}")->assertOk();
    }

    public function test_show_includes_loyalty_history(): void
    {
        $tenantId = 'tenant-customers-2';
        $this->actingBackOfficeSession($tenantId);
        $customer = $this->makeCustomer($tenantId, ['loyalty_points' => 25]);

        LoyaltyTransaction::create([
            'id' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'points' => 25, 'type' => 'earn', 'note' => 'Opening bonus',
        ]);

        $response = $this->get("/office/customers/{$customer->id}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('customer.id', $customer->id)
            ->has('loyalty_history', 1)
        );
    }

    public function test_update_edits_editable_fields_but_preserves_the_ledger_derived_balances(): void
    {
        $tenantId = 'tenant-customers-3';
        $this->actingBackOfficeSession($tenantId);
        $customer = $this->makeCustomer($tenantId, ['loyalty_points' => 50, 'credit_balance' => 12.5]);

        $this->put("/office/customers/{$customer->id}", [
            'name' => 'Jane Updated',
            'credit_limit' => 100,
            'is_tax_exempt' => true,
        ])->assertRedirect();

        $customer->refresh();
        $this->assertSame('Jane Updated', $customer->name);
        $this->assertSame('100.0000', $customer->credit_limit);
        $this->assertTrue($customer->is_tax_exempt);
        // Ledger-derived fields are recomputed from an empty ledger, not clobbered by the form.
        $this->assertSame('0.0000', $customer->loyalty_points);
    }

    public function test_cashier_cannot_manage_customers(): void
    {
        $tenantId = 'tenant-customers-4';
        $this->actingBackOfficeSession($tenantId, 'cashier');
        $customer = $this->makeCustomer($tenantId);

        $this->get('/office/customers')->assertForbidden();
        $this->put("/office/customers/{$customer->id}", ['name' => 'Nope'])->assertForbidden();
    }

    public function test_customers_are_scoped_to_the_current_tenant_on_update(): void
    {
        $foreignCustomer = $this->makeCustomer('tenant-customers-other-2');

        $tenantId = 'tenant-customers-5';
        $this->actingBackOfficeSession($tenantId);

        $this->put("/office/customers/{$foreignCustomer->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Jane Shopper', $foreignCustomer->fresh()->name);
    }
}
