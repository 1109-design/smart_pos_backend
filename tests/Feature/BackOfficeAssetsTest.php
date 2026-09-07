<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Asset;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeAssetsTest extends TestCase
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

    public function test_create_and_update_an_asset(): void
    {
        $tenantId = 'tenant-assets-1';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/assets', [
            'name' => 'Delivery Van',
            'category' => 'Vehicle',
            'purchase_date' => '2024-01-01',
            'purchase_cost' => 20000,
            'salvage_value' => 2000,
            'depreciation_method' => 'straight_line',
            'useful_life_years' => 5,
        ])->assertRedirect();

        $asset = Asset::where('business_id', $tenantId)->first();
        $this->assertNotNull($asset);
        $this->assertSame('Delivery Van', $asset->name);
        $this->assertSame('active', $asset->status);
        $this->assertDatabaseHas('sync_records', ['table_name' => 'assets', 'record_uuid' => $asset->id]);

        $this->put("/office/assets/{$asset->id}", [
            'name' => 'Delivery Van (Toyota)',
            'category' => 'Vehicle',
            'purchase_date' => '2024-01-01',
            'purchase_cost' => 20000,
            'salvage_value' => 2000,
            'depreciation_method' => 'straight_line',
            'useful_life_years' => 5,
        ])->assertRedirect();
        $this->assertSame('Delivery Van (Toyota)', $asset->fresh()->name);

        $this->get('/office/assets')->assertOk();
    }

    public function test_straight_line_valuation_depreciates_toward_salvage(): void
    {
        $asset = Asset::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'tenant-x',
            'name' => 'Fridge',
            'category' => 'Equipment',
            'purchase_date' => now()->subYears(2),
            'purchase_cost' => 1000,
            'salvage_value' => 100,
            'depreciation_method' => 'straight_line',
            'useful_life_years' => 4,
            'status' => 'active',
        ]);

        $valuation = $asset->valuation();

        // 2 of 4 years elapsed: half of (1000-100) depreciated = 450, book value ~550.
        $this->assertEqualsWithDelta(550, $valuation['book_value'], 5);
        $this->assertEqualsWithDelta(450, $valuation['accumulated_depreciation'], 5);
    }

    public function test_dispose_freezes_the_asset_value(): void
    {
        $tenantId = 'tenant-assets-2';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/assets', [
            'name' => 'Till Register',
            'category' => 'Equipment',
            'purchase_date' => '2023-01-01',
            'purchase_cost' => 500,
            'depreciation_method' => 'none',
        ])->assertRedirect();
        $asset = Asset::where('business_id', $tenantId)->first();

        $this->post("/office/assets/{$asset->id}/dispose", [
            'disposed_at' => now()->toDateString(),
            'disposal_value' => 50,
        ])->assertRedirect();

        $asset->refresh();
        $this->assertSame('disposed', $asset->status);
        $this->assertEquals(50, $asset->disposal_value);
        $this->assertSame(50.0, $asset->valuation()['book_value']);
    }

    public function test_cashier_cannot_manage_assets(): void
    {
        $tenantId = 'tenant-assets-3';
        $this->actingBackOfficeSession($tenantId, 'cashier');

        $this->get('/office/assets')->assertForbidden();
        $this->post('/office/assets', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_assets_are_scoped_to_the_current_tenant(): void
    {
        $otherTenantId = 'tenant-assets-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => 'YYYYYY']);
        $foreignAsset = Asset::create([
            'id' => (string) Str::uuid(),
            'business_id' => $otherTenantId,
            'name' => 'Their Asset',
            'category' => 'Equipment',
            'purchase_date' => now(),
            'purchase_cost' => 100,
            'status' => 'active',
        ]);

        $tenantId = 'tenant-assets-4';
        $this->actingBackOfficeSession($tenantId);

        $this->put("/office/assets/{$foreignAsset->id}", [
            'name' => 'Hijacked',
            'category' => 'Equipment',
            'purchase_date' => now()->toDateString(),
            'purchase_cost' => 100,
            'depreciation_method' => 'none',
        ])->assertNotFound();
        $this->assertSame('Their Asset', $foreignAsset->fresh()->name);
    }
}
