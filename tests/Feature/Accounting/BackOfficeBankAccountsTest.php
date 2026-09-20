<?php

namespace Tests\Feature\Accounting;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Accounting\GlAccount;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeBankAccountsTest extends TestCase
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

    public function test_index_lists_the_businesss_bank_accounts(): void
    {
        $tenantId = 'tenant-bank-1';
        $this->actingBackOfficeSession($tenantId);
        $this->post('/office/bank-accounts', ['name' => 'CBZ Main Account']);

        $response = $this->get('/office/bank-accounts');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('accounts', 1));
    }

    public function test_creating_a_bank_account_provisions_its_own_gl_account(): void
    {
        $tenantId = 'tenant-bank-2';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/bank-accounts', [
            'name' => 'CBZ Main Account',
            'account_number' => '1234567890',
        ])->assertRedirect();

        $bankAccount = BankAccount::where('business_id', $tenantId)->firstOrFail();
        $this->assertSame('CBZ Main Account', $bankAccount->name);
        $this->assertNotNull($bankAccount->gl_account_id);
    }

    public function test_deactivating_a_bank_account_marks_it_inactive(): void
    {
        $tenantId = 'tenant-bank-3';
        $this->actingBackOfficeSession($tenantId);
        $this->post('/office/bank-accounts', ['name' => 'CBZ Main Account']);
        $bankAccount = BankAccount::where('business_id', $tenantId)->firstOrFail();

        $this->post("/office/bank-accounts/{$bankAccount->id}/deactivate")->assertRedirect();

        $this->assertFalse($bankAccount->fresh()->is_active);
    }

    public function test_a_business_cannot_deactivate_another_businesss_bank_account(): void
    {
        $tenantId = 'tenant-bank-4';
        $otherTenantId = 'tenant-bank-5';

        $this->actingBackOfficeSession($tenantId);
        $this->post('/office/bank-accounts', ['name' => 'Tenant Four Bank']);
        $foreignAccount = BankAccount::where('business_id', $tenantId)->firstOrFail();

        // A different business's own BackOffice session must not be able to
        // touch the first business's bank account, even by guessing its id.
        $this->actingBackOfficeSession($otherTenantId);

        $this->post("/office/bank-accounts/{$foreignAccount->id}/deactivate")->assertForbidden();
    }

    public function test_creating_a_bank_account_records_branch_code_and_swift_code(): void
    {
        $tenantId = 'tenant-bank-7';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/bank-accounts', [
            'name' => 'CBZ Main Account',
            'branch_code' => '10123',
            'swift_code' => 'CBZWZWHA',
        ])->assertRedirect();

        $bankAccount = BankAccount::where('business_id', $tenantId)->firstOrFail();
        $this->assertSame('10123', $bankAccount->branch_code);
        $this->assertSame('CBZWZWHA', $bankAccount->swift_code);
    }

    public function test_linking_an_existing_gl_account_does_not_mint_a_new_one(): void
    {
        $tenantId = 'tenant-bank-8';
        $this->actingBackOfficeSession($tenantId);
        $existing = GlAccount::where('business_id', $tenantId)->where('code', '1020')->firstOrFail();
        $countBefore = GlAccount::where('business_id', $tenantId)->count();

        $this->post('/office/bank-accounts', [
            'name' => 'Mobile Money Clearing Account',
            'gl_account_id' => $existing->id,
        ])->assertRedirect();

        $bankAccount = BankAccount::where('business_id', $tenantId)->firstOrFail();
        $this->assertSame($existing->id, $bankAccount->gl_account_id);
        $this->assertSame($countBefore, GlAccount::where('business_id', $tenantId)->count());
    }

    public function test_linking_a_gl_account_already_claimed_by_another_active_bank_account_is_rejected(): void
    {
        $tenantId = 'tenant-bank-9';
        $this->actingBackOfficeSession($tenantId);
        $this->post('/office/bank-accounts', ['name' => 'First Bank']);
        $firstGlAccountId = BankAccount::where('business_id', $tenantId)->firstOrFail()->gl_account_id;

        $this->post('/office/bank-accounts', [
            'name' => 'Second Bank, same account',
            'gl_account_id' => $firstGlAccountId,
        ])->assertStatus(422);

        $this->assertSame(1, BankAccount::where('business_id', $tenantId)->count());
    }

    public function test_linking_a_non_asset_gl_account_is_rejected(): void
    {
        $tenantId = 'tenant-bank-10';
        $this->actingBackOfficeSession($tenantId);
        $expenseAccount = GlAccount::where('business_id', $tenantId)->where('code', '6000')->firstOrFail();

        $this->post('/office/bank-accounts', [
            'name' => 'Wrong Category',
            'gl_account_id' => $expenseAccount->id,
        ])->assertNotFound();
    }

    public function test_cash_book_shows_balance_and_activity(): void
    {
        $tenantId = 'tenant-bank-6';
        $this->actingBackOfficeSession($tenantId);
        $this->post('/office/bank-accounts', ['name' => 'CBZ Main Account']);
        $bankAccount = BankAccount::where('business_id', $tenantId)->firstOrFail();

        $response = $this->get("/office/bank-accounts/{$bankAccount->id}/cash-book");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('balance', 0)
            ->has('activity', 0)
        );
    }
}
