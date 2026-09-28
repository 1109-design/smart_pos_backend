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

    public function test_owner_can_register_an_asset_and_it_posts_the_acquisition(): void
    {
        $this->actingBackOfficeSession();
        $this->fundCash(20000);

        $response = $this->post('/office/assets', [
            'name' => 'Delivery Van',
            'acquisition_date' => '2026-02-01',
            'acquisition_cost' => 12000,
            'useful_life_months' => 24,
            'funding_method' => 'cash',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('assets', ['business_id' => $this->tenantId, 'name' => 'Delivery Van', 'status' => 'active']);
        $this->assertSame(12000.0, $this->account('1500')->balance());
        $this->assertSame(8000.0, $this->account('1000')->balance());
    }

    public function test_index_reports_book_value_for_each_asset(): void
    {
        $this->actingBackOfficeSession();
        $this->fundCash(20000);

        $this->post('/office/assets', [
            'name' => 'Laptop',
            'acquisition_date' => '2026-02-01',
            'acquisition_cost' => 1000,
            'useful_life_months' => 10,
            'funding_method' => 'cash',
        ]);

        $response = $this->get('/office/assets');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('assets.0.name', 'Laptop')
            ->where('assets.0.book_value', 1000)
        );
    }

    public function test_owner_can_dispose_of_an_asset(): void
    {
        $this->actingBackOfficeSession();
        $this->fundCash(20000);

        $this->post('/office/assets', [
            'name' => 'Old Fridge', 'acquisition_date' => '2026-01-01',
            'acquisition_cost' => 500, 'useful_life_months' => 12, 'funding_method' => 'cash',
        ]);
        $asset = Asset::where('business_id', $this->tenantId)->firstOrFail();

        $response = $this->post("/office/assets/{$asset->id}/dispose", [
            'disposed_at' => '2026-03-01',
            'disposal_proceeds' => 500,
        ]);

        $response->assertRedirect();
        $this->assertSame('disposed', $asset->fresh()->status);
        $this->assertSame(0.0, $this->account('1500')->balance());
    }

    public function test_a_disposed_asset_cannot_be_disposed_of_again(): void
    {
        $this->actingBackOfficeSession();
        $this->fundCash(20000);

        $asset = Asset::create([
            'id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'name' => 'Old Fridge',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => 500, 'salvage_value' => 0,
            'useful_life_months' => 12, 'funding_method' => 'cash', 'status' => 'disposed',
            'disposed_at' => '2026-02-01', 'disposal_proceeds' => 100, 'created_by_user_id' => (string) Str::uuid(),
        ]);

        $this->post("/office/assets/{$asset->id}/dispose", [
            'disposed_at' => '2026-03-01', 'disposal_proceeds' => 100,
        ])->assertStatus(422);
    }

    public function test_manager_cannot_manage_assets_by_default(): void
    {
        $this->actingBackOfficeSession('manager');

        $this->get('/office/assets')->assertForbidden();
        $this->post('/office/assets', ['name' => 'Nope'])->assertForbidden();
    }
}
