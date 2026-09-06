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

    public function test_index_shows_the_current_balance_and_activity(): void
    {
        $tenantId = 'tenant-vault-1';
        $this->actingBackOfficeSession($tenantId);
        $this->fundCash($tenantId, 500);
        $this->post('/office/cash-vault/drop', ['amount' => 200, 'date' => '2026-06-05']);

        $response = $this->get('/office/cash-vault');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('balance', 200)
            ->has('activity', 1)
        );
    }

    public function test_recording_a_drop_reduces_cash_and_increases_the_vault(): void
    {
        $tenantId = 'tenant-vault-2';
        $this->actingBackOfficeSession($tenantId);
        $this->fundCash($tenantId, 500);

        $this->post('/office/cash-vault/drop', ['amount' => 150, 'date' => '2026-06-05', 'note' => 'EOD drop'])
            ->assertRedirect();

        $this->assertSame(350.0, $this->account($tenantId, '1000')->balance());
        $this->assertSame(150.0, $this->account($tenantId, '1005')->balance());
    }

    public function test_recording_a_deposit_moves_vault_funds_to_bank(): void
    {
        $tenantId = 'tenant-vault-3';
        $this->actingBackOfficeSession($tenantId);
        $this->fundCash($tenantId, 500);
        $this->post('/office/cash-vault/drop', ['amount' => 300, 'date' => '2026-06-05']);

        $this->post('/office/cash-vault/deposit', ['amount' => 200, 'date' => '2026-06-06'])->assertRedirect();

        $this->assertSame(100.0, $this->account($tenantId, '1005')->balance());
        $this->assertSame(200.0, $this->account($tenantId, '1010')->balance());
    }

    public function test_recording_a_count_with_a_shortfall_posts_a_variance(): void
    {
        $tenantId = 'tenant-vault-4';
        $this->actingBackOfficeSession($tenantId);
        $this->fundCash($tenantId, 500);
        $this->post('/office/cash-vault/drop', ['amount' => 200, 'date' => '2026-06-05']);

        $response = $this->post('/office/cash-vault/count', ['counted_amount' => 180, 'date' => '2026-06-10']);
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(180.0, $this->account($tenantId, '1005')->balance());
        $this->assertSame(20.0, $this->account($tenantId, '6065')->balance());
    }

    public function test_cashier_cannot_access_the_cash_vault(): void
    {
        $tenantId = 'tenant-vault-5';
        $this->actingBackOfficeSession($tenantId, 'cashier');

        $this->get('/office/cash-vault')->assertForbidden();
        $this->post('/office/cash-vault/drop', ['amount' => 10, 'date' => '2026-06-05'])->assertForbidden();
    }

    public function test_a_drop_that_would_overdraw_cash_shows_a_validation_error(): void
    {
        $tenantId = 'tenant-vault-6';
        $this->actingBackOfficeSession($tenantId);
        $this->fundCash($tenantId, 100);

        $response = $this->post('/office/cash-vault/drop', ['amount' => 500, 'date' => '2026-06-05']);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0.0, $this->account($tenantId, '1005')->balance());
    }
}
