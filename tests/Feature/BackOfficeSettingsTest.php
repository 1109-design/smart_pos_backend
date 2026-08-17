<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Business;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeSettingsTest extends TestCase
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

    /**
     * Seeds real ledger-backed opening stock, the way every genuine write in
     * this app creates it — a bare `stock_quantity` column write with no
     * backing stock_movements row never happens outside a test, and would
     * make recomputeProductStock's ledger sum diverge from the fixture.
     */
    private function seedOpeningStock(string $tenantId, string $productId, float $quantity, ?string $locationId): void
    {
        app(SyncProcessor::class)->process('stock_movements', (string) Str::uuid(), 'upsert', [
            'business_id' => $tenantId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => $quantity,
        ]);
    }

    public function test_owner_can_zero_out_stock_everywhere_in_one_shot(): void
    {
        $tenantId = 'tenant-office-settings-1';
        $user = $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Main']);

        // A product with stock split across a known location and an
        // "unattributed" remainder (as if opening stock predates locations).
        $product = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Coca Cola 500ml',
            'item_type' => 'product',
            'price' => 1.5,
            'stock_quantity' => 0,
        ]);
        $this->seedOpeningStock($tenantId, $product->id, 20, $location->id);
        $this->seedOpeningStock($tenantId, $product->id, 10, null);

        // A service must never be touched.
        $service = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Repair',
            'item_type' => 'service',
            'price' => 25,
            'stock_quantity' => 0,
        ]);

        $response = $this->post('/office/settings/reset-stock', ['confirm' => 'RESET']);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertSame('0.0000', $product->fresh()->stock_quantity);
        $this->assertSame('0.0000', ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->first()->quantity);
        $this->assertSame('0.0000', $service->fresh()->stock_quantity);

        // Ledger-backed, not a raw UPDATE.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity_change' => -20,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'location_id' => null,
            'quantity_change' => -10,
        ]);

        $business = Business::find($tenantId);
        $this->assertNotNull($business->stock_reset_at);
        $this->assertSame($user->id, $business->stock_reset_by_user_id);
    }

    public function test_reset_cannot_run_a_second_time(): void
    {
        $tenantId = 'tenant-office-settings-2';
        $this->actingBackOfficeSession($tenantId);

        Business::create(['id' => $tenantId, 'name' => $tenantId, 'stock_reset_at' => now()->subDay(), 'stock_reset_by_user_id' => (string) Str::uuid()]);

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Untouched Item',
            'item_type' => 'product',
            'price' => 5,
            'stock_quantity' => 40,
        ]);

        $response = $this->post('/office/settings/reset-stock', ['confirm' => 'RESET']);

        $response->assertSessionHasErrors('stock_reset');
        $this->assertSame('40.0000', $product->fresh()->stock_quantity);
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $product->id]);
    }

    public function test_confirmation_word_is_required(): void
    {
        $tenantId = 'tenant-office-settings-3';
        $this->actingBackOfficeSession($tenantId);

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Untouched Item',
            'item_type' => 'product',
            'price' => 5,
            'stock_quantity' => 40,
        ]);

        $this->post('/office/settings/reset-stock', ['confirm' => 'reset'])->assertSessionHasErrors('confirm');
        $this->post('/office/settings/reset-stock', [])->assertSessionHasErrors('confirm');

        $this->assertSame('40.0000', $product->fresh()->stock_quantity);
        $this->assertNull(Business::find($tenantId)?->stock_reset_at);
    }

    public function test_non_owner_roles_are_forbidden(): void
    {
        $tenantId = 'tenant-office-settings-4';
        $this->actingBackOfficeSession($tenantId, 'manager');

        $this->get('/office/settings')->assertForbidden();
        $this->post('/office/settings/reset-stock', ['confirm' => 'RESET'])->assertForbidden();
    }

    public function test_reset_never_touches_another_businesses_stock(): void
    {
        $tenantId = 'tenant-office-settings-mine';
        $this->actingBackOfficeSession($tenantId);

        $mine = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Mine',
            'item_type' => 'product',
            'price' => 5,
            'stock_quantity' => 0,
        ]);
        $this->seedOpeningStock($tenantId, $mine->id, 10, null);

        $otherTenantId = 'tenant-office-settings-other';
        Tenant::create(['id' => $otherTenantId, 'business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => '654321']);
        $other = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $otherTenantId,
            'name' => 'Not mine',
            'item_type' => 'product',
            'price' => 5,
            'stock_quantity' => 0,
        ]);
        $this->seedOpeningStock($otherTenantId, $other->id, 999, null);

        $this->post('/office/settings/reset-stock', ['confirm' => 'RESET'])->assertSessionHasNoErrors();

        $this->assertSame('0.0000', $mine->fresh()->stock_quantity);
        $this->assertSame('999.0000', $other->fresh()->stock_quantity);
        $this->assertNull(Business::find($otherTenantId));
    }
}
