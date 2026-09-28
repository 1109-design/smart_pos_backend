<?php

namespace Tests\Feature\Accounting;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\Business;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeBankReconciliationTest extends TestCase
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

    private function makeBankAccount(string $tenantId): BankAccount
    {
        $this->post('/office/bank-accounts', ['name' => 'CBZ Main Account']);

        return BankAccount::where('business_id', $tenantId)->firstOrFail();
    }

    private function postDeposit(string $tenantId, GlAccount $bankGl, float $amount, string $date): void
    {
        $journals = app(JournalService::class);
        $revenue = GlAccount::where('business_id', $tenantId)->where('code', '4000')->firstOrFail();
        $header = $journals->createDraft($tenantId, $date, 'test_deposit', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $bankGl->id, 'debit' => $amount]);
        $journals->addLine($header, ['gl_account_id' => $revenue->id, 'credit' => $amount]);
        $journals->post($header);
    }

    public function test_show_renders_the_start_form_when_no_session_is_in_progress(): void
    {
        $tenantId = 'tenant-recon-1';
        $this->actingBackOfficeSession($tenantId);
        $bankAccount = $this->makeBankAccount($tenantId);

        $response = $this->get("/office/bank-accounts/{$bankAccount->id}/reconcile");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('reconciliation', null));
    }

    public function test_starting_creates_an_in_progress_session(): void
    {
        $tenantId = 'tenant-recon-2';
        $this->actingBackOfficeSession($tenantId);
        $bankAccount = $this->makeBankAccount($tenantId);

        $this->post("/office/bank-accounts/{$bankAccount->id}/reconcile/start", [
            'statement_date' => '2026-06-30',
            'statement_balance' => 100,
        ])->assertRedirect();

        $this->assertSame('in_progress', BankReconciliation::where('bank_account_id', $bankAccount->id)->firstOrFail()->status);
    }

    public function test_toggling_a_line_and_completing_when_balances_match(): void
    {
        $tenantId = 'tenant-recon-3';
        $this->actingBackOfficeSession($tenantId);
        $bankAccount = $this->makeBankAccount($tenantId);
        $bankGl = GlAccount::find($bankAccount->gl_account_id);
        $this->postDeposit($tenantId, $bankGl, 100.0, '2026-06-15');
        $entry = GeneralLedgerEntry::where('gl_account_id', $bankGl->id)->firstOrFail();

        $this->post("/office/bank-accounts/{$bankAccount->id}/reconcile/start", [
            'statement_date' => '2026-06-30',
            'statement_balance' => 100,
        ]);
        $this->post("/office/bank-accounts/{$bankAccount->id}/reconcile/toggle", [
            'entry_id' => $entry->id,
            'cleared' => true,
        ])->assertRedirect();

        $this->post("/office/bank-accounts/{$bankAccount->id}/reconcile/complete")->assertRedirect();

        $this->assertSame('completed', BankReconciliation::where('bank_account_id', $bankAccount->id)->firstOrFail()->status);
    }

    public function test_completing_fails_when_balances_do_not_match(): void
    {
        $tenantId = 'tenant-recon-4';
        $this->actingBackOfficeSession($tenantId);
        $bankAccount = $this->makeBankAccount($tenantId);

        $this->post("/office/bank-accounts/{$bankAccount->id}/reconcile/start", [
            'statement_date' => '2026-06-30',
            'statement_balance' => 500,
        ]);

        $this->post("/office/bank-accounts/{$bankAccount->id}/reconcile/complete")
            ->assertSessionHasErrors('statement_balance');

        $this->assertSame('in_progress', BankReconciliation::where('bank_account_id', $bankAccount->id)->firstOrFail()->status);
    }

    public function test_cancelling_marks_the_session_cancelled(): void
    {
        $tenantId = 'tenant-recon-5';
        $this->actingBackOfficeSession($tenantId);
        $bankAccount = $this->makeBankAccount($tenantId);
        $this->post("/office/bank-accounts/{$bankAccount->id}/reconcile/start", [
            'statement_date' => '2026-06-30',
            'statement_balance' => 0,
        ]);

        $this->post("/office/bank-accounts/{$bankAccount->id}/reconcile/cancel")->assertRedirect();

        $this->assertSame('cancelled', BankReconciliation::where('bank_account_id', $bankAccount->id)->firstOrFail()->status);
    }

    public function test_history_lists_past_sessions(): void
    {
        $tenantId = 'tenant-recon-6';
        $this->actingBackOfficeSession($tenantId);
        $bankAccount = $this->makeBankAccount($tenantId);
        $this->post("/office/bank-accounts/{$bankAccount->id}/reconcile/start", [
            'statement_date' => '2026-06-30',
            'statement_balance' => 0,
        ]);
        $this->post("/office/bank-accounts/{$bankAccount->id}/reconcile/complete");

        $response = $this->get("/office/bank-accounts/{$bankAccount->id}/reconciliation-history");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('sessions', 1));
    }

    public function test_a_business_cannot_reconcile_another_businesss_bank_account(): void
    {
        $tenantId = 'tenant-recon-7';
        $otherTenantId = 'tenant-recon-8';

        $this->actingBackOfficeSession($tenantId);
        $bankAccount = $this->makeBankAccount($tenantId);

        $this->actingBackOfficeSession($otherTenantId);

        $this->get("/office/bank-accounts/{$bankAccount->id}/reconcile")->assertForbidden();
        $this->post("/office/bank-accounts/{$bankAccount->id}/reconcile/start", [
            'statement_date' => '2026-06-30',
            'statement_balance' => 0,
        ])->assertForbidden();
    }
}
