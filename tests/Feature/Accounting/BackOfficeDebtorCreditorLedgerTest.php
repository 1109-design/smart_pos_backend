<?php

namespace Tests\Feature\Accounting;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end coverage for Phase 11c's BackOffice surface: a customer's
 * posted credit sale should actually show up on their statement page and
 * in the Debtor Age Analysis list — proving the wiring from GL to
 * PartyLedgerService to the Inertia pages, not just the service in
 * isolation (already covered by PartyLedgerServiceTest).
 */
class BackOfficeDebtorCreditorLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId, string $role = 'business_owner'): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);
        Business::firstOrCreate(['id' => $tenantId], ['name' => $tenantId, 'currency_code' => 'USD']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);

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

    private function account(string $tenantId, string $code): GlAccount
    {
        return GlAccount::where('business_id', $tenantId)->where('code', $code)->firstOrFail();
    }

    private function postCreditSale(string $tenantId, string $customerId, float $amount): void
    {
        $journals = app(JournalService::class);
        $header = $journals->createDraft($tenantId, now()->toDateString(), 'sale', (string) Str::uuid());
        $journals->addLine($header, [
            'gl_account_id' => $this->account($tenantId, '1100')->id,
            'debit' => $amount,
            'party_type' => 'customer',
            'party_id' => $customerId,
        ]);
        $journals->addLine($header, ['gl_account_id' => $this->account($tenantId, '4000')->id, 'credit' => $amount]);
        $journals->post($header);
    }

    public function test_customer_show_page_carries_their_ledger_statement_and_aging(): void
    {
        $tenantId = 'tenant-debtor-1';
        $this->actingBackOfficeSession($tenantId);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Jane Shopper']);

        $this->postCreditSale($tenantId, $customer->id, 150);

        $response = $this->get("/office/customers/{$customer->id}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('aging.total_outstanding', 150)
            ->has('statement.lines', 1)
            ->where('statement.closing_balance', 150)
        );
    }

    public function test_supplier_show_page_exists_and_carries_an_empty_ledger_by_default(): void
    {
        $tenantId = 'tenant-creditor-1';
        $this->actingBackOfficeSession($tenantId);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);

        $response = $this->get("/office/suppliers/{$supplier->id}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('supplier.id', $supplier->id)
            ->where('aging.total_outstanding', 0)
            ->has('statement.lines', 0)
        );
    }

    public function test_debtor_age_analysis_lists_only_customers_with_a_balance(): void
    {
        $tenantId = 'tenant-debtor-2';
        $this->actingBackOfficeSession($tenantId);
        $owes = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Owes Money']);
        $clean = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Clean Account']);

        $this->postCreditSale($tenantId, $owes->id, 42);

        $response = $this->get('/office/reports/debtors');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('type', 'debtor')
            ->has('rows', 1)
            ->where('rows.0.name', 'Owes Money')
            ->where('rows.0.total_outstanding', 42)
        );
    }

    public function test_creditor_age_analysis_route_works_and_is_empty_with_no_purchasing_activity(): void
    {
        $tenantId = 'tenant-creditor-2';
        $this->actingBackOfficeSession($tenantId);
        Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);

        $response = $this->get('/office/reports/creditors');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('type', 'creditor')
            ->has('rows', 0)
        );
    }

    public function test_cashier_cannot_view_debtor_or_creditor_reports(): void
    {
        $tenantId = 'tenant-aging-perm-1';
        $this->actingBackOfficeSession($tenantId, 'cashier');

        $this->get('/office/reports/debtors')->assertForbidden();
        $this->get('/office/reports/creditors')->assertForbidden();
    }

    public function test_a_customers_statement_is_scoped_to_the_current_tenant(): void
    {
        $foreignCustomer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => 'tenant-debtor-other', 'name' => 'Not Yours']);

        $tenantId = 'tenant-debtor-3';
        $this->actingBackOfficeSession($tenantId);

        $this->get("/office/customers/{$foreignCustomer->id}")->assertNotFound();
    }
}
