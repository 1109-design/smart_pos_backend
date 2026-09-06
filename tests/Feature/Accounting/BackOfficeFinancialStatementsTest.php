<?php

namespace Tests\Feature\Accounting;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeFinancialStatementsTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-fs-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::create(['id' => $this->tenantId, 'business_name' => $this->tenantId, 'owner_email' => 'a@example.com', 'pairing_code' => substr(md5($this->tenantId), 0, 6)]);
        Business::create(['id' => $this->tenantId, 'name' => $this->tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->tenantId);

        $journals = app(JournalService::class);
        $header = $journals->createDraft($this->tenantId, '2026-06-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $this->account('1000')->id, 'debit' => 1000]);
        $journals->addLine($header, ['gl_account_id' => $this->account('3000')->id, 'credit' => 1000]);
        $journals->post($header);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->tenantId)->where('code', $code)->firstOrFail();
    }

    private function actingBackOfficeAs(string $role): void
    {
        $user = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'is_active' => true]);

        session(['backoffice' => [
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'user_name' => $user->name,
            'user_email' => $user->email, 'role' => $role, 'business_name' => $this->tenantId,
            'currency_code' => 'USD',
        ]]);
    }

    public function test_owner_can_view_the_trial_balance(): void
    {
        $this->actingBackOfficeAs('business_owner');

        $response = $this->get('/office/reports/trial-balance?as_of=2026-06-30');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('report.is_balanced', true)
            ->where('report.total_debit', 1000)
        );
    }

    public function test_owner_can_view_the_income_statement(): void
    {
        $this->actingBackOfficeAs('business_owner');

        $response = $this->get('/office/reports/income-statement?from=2026-06-01&to=2026-06-30');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('report.sections'));
    }

    public function test_owner_can_view_the_balance_sheet(): void
    {
        $this->actingBackOfficeAs('business_owner');

        $response = $this->get('/office/reports/balance-sheet?as_of=2026-06-30');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('report.is_balanced', true)
            ->where('report.total_assets', 1000)
        );
    }

    public function test_cashier_cannot_view_financial_statements(): void
    {
        $this->actingBackOfficeAs('cashier');

        $this->get('/office/reports/trial-balance')->assertForbidden();
        $this->get('/office/reports/income-statement')->assertForbidden();
        $this->get('/office/reports/balance-sheet')->assertForbidden();
    }
}
