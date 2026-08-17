<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Location;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeLocationsTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId): User
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
                'role' => 'business_owner',
                'business_name' => $tenantId,
                'currency_code' => 'USD',
            ],
        ]);

        return $user;
    }

    public function test_index_seeds_a_default_location_and_backfills_existing_stock(): void
    {
        $tenantId = 'tenant-loc-1';
        $this->actingBackOfficeSession($tenantId);

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Legacy Item',
            'item_type' => 'product',
            'price' => 10,
            'track_stock' => true,
            'stock_quantity' => 7,
        ]);

        $response = $this->get('/office/locations');
        $response->assertOk();

        $location = Location::where('business_id', $tenantId)->first();
        $this->assertNotNull($location);
        $this->assertSame('Main', $location->name);

        $this->assertDatabaseHas('product_stock', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 7,
        ]);

        $this->assertDatabaseHas('sync_records', [
            'table_name' => 'locations',
            'record_uuid' => $location->id,
            'device_id' => null,
        ]);

        // Calling it again must not create a second default location.
        $this->get('/office/locations');
        $this->assertSame(1, Location::where('business_id', $tenantId)->count());
    }

    public function test_location_can_be_created_and_appears_in_sync_stream(): void
    {
        $tenantId = 'tenant-loc-2';
        $this->actingBackOfficeSession($tenantId);

        $response = $this->post('/office/locations', [
            'name' => 'Downtown Shop',
            'type' => 'shop',
        ]);
        $response->assertRedirect();

        $location = Location::where('name', 'Downtown Shop')->first();
        $this->assertNotNull($location);
        $this->assertSame($tenantId, $location->business_id);
        $this->assertTrue($location->is_active);

        $this->assertDatabaseHas('sync_records', [
            'table_name' => 'locations',
            'record_uuid' => $location->id,
        ]);
    }

    public function test_toggle_active_scoped_to_tenant(): void
    {
        $otherTenantId = 'tenant-loc-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => 'ZZZZZZ']);
        $foreignLocation = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => $otherTenantId,
            'name' => 'Not Yours',
            'type' => 'shop',
            'is_active' => true,
        ]);

        $tenantId = 'tenant-loc-3';
        $this->actingBackOfficeSession($tenantId);

        $this->patch("/office/locations/{$foreignLocation->id}/toggle-active")->assertNotFound();
        $this->assertDatabaseHas('locations', ['id' => $foreignLocation->id, 'is_active' => true]);
    }
}
