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

class BackOfficeCashVaultTest extends TestCase
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

    private function account(string $tenantId, string $code): GlAccount
    {
        return GlAccount::where('business_id', $tenantId)->where('code', $code)->firstOrFail();
    }

    private function fundCash(string $tenantId, float $amount): void
    {
        $journals = app(JournalService::class);
        $header = $journals->createDraft($tenantId, '2026-06-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $this->account($tenantId, '1000')->id, 'debit' => $amount]);
        $journals->addLine($header, ['gl_account_id' => $this->account($tenantId, '3000')->id, 'credit' => $amount]);
        $journals->post($header);
    }

    public function test_cashier_cannot_access_the_cash_vault(): void
    {
        $tenantId = 'tenant-vault-5';
        $this->actingBackOfficeSession($tenantId, 'cashier');

        $this->get('/office/cash-vault')->assertForbidden();
        $this->post('/office/cash-vault/drop', ['amount' => 10, 'date' => '2026-06-05'])->assertForbidden();
    }
}
