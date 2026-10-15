<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Accounting\GlAccount;
use App\Models\Asset;
use App\Models\Business;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9 / Phase 11d — the BackOffice side of the asset register: creating
 * an asset (which posts its acquisition), disposing of one, and the
 * owner-only permission gate on both.
 */
class BackOfficeAssetsTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-assets-1';

    private function actingBackOfficeSession(string $role = 'business_owner'): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::firstOrCreate(['id' => $this->tenantId], ['business_name' => $this->tenantId, 'owner_email' => $this->tenantId.'@example.com', 'pairing_code' => substr(md5($this->tenantId), 0, 6)]);
        Business::firstOrCreate(['id' => $this->tenantId], ['name' => $this->tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->tenantId);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(), 'business_id' => $this->tenantId,
            'email' => $this->tenantId.'-user@example.com', 'is_active' => true,
        ]);

        session(['backoffice' => [
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'user_name' => $user->name,
            'user_email' => $user->email, 'role' => $role, 'business_name' => $this->tenantId,
            'currency_code' => 'USD',
        ]]);

        return $user;
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->tenantId)->where('code', $code)->firstOrFail();
    }

    private function fundCash(float $amount): void
    {
        $journals = app(JournalService::class);
        $header = $journals->createDraft($this->tenantId, '2026-01-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $this->account('1000')->id, 'debit' => $amount]);
        $journals->addLine($header, ['gl_account_id' => $this->account('3000')->id, 'credit' => $amount]);
        $journals->post($header);
    }

    public function test_manager_cannot_manage_assets_by_default(): void
    {
        $this->actingBackOfficeSession('manager');

        $this->get('/office/assets')->assertForbidden();
        $this->post('/office/assets', ['name' => 'Nope'])->assertForbidden();
    }
}
