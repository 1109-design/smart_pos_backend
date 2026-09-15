<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeStockInventoryByLocationTest extends TestCase
{
    use RefreshDatabase;

    /** See BackOfficeReportsTest for why the middleware is bypassed here. */
    private function actingBackOfficeSession(string $tenantId, string $role = 'business_owner', ?User $user = null): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);

        $user ??= User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'email' => $tenantId.'-owner@example.com',
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

    public function test_pivots_quantity_and_cost_value_per_location(): void
    {
        $tenantId = 'tenant-inv-loc-pivot';
        $this->actingBackOfficeSession($tenantId);

        $branchA = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch A', 'is_active' => true]);
        $branchB = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'is_active' => true]);

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 20, 'cost_price' => 5, 'track_stock' => true, 'is_active' => true,
        ]);
        ProductStock::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'location_id' => $branchA->id, 'quantity' => 10]);
        ProductStock::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'location_id' => $branchB->id, 'quantity' => 4]);

        $response = $this->get('/office/reports/inventory-by-location');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('BackOffice/StockInventoryByLocation')
            ->where('rows.0.name', 'Widget')
            ->where("rows.0.locations.{$branchA->id}.quantity", fn ($v) => (float) $v === 10.0)
            ->where("rows.0.locations.{$branchA->id}.value", fn ($v) => (float) $v === 50.0)
            ->where("rows.0.locations.{$branchB->id}.quantity", fn ($v) => (float) $v === 4.0)
            ->where("rows.0.locations.{$branchB->id}.value", fn ($v) => (float) $v === 20.0)
            ->where('rows.0.total_quantity', fn ($v) => (float) $v === 14.0)
            ->where('rows.0.total_value', fn ($v) => (float) $v === 70.0)
            ->where('totals.quantity', fn ($v) => (float) $v === 14.0)
            ->where('totals.value', fn ($v) => (float) $v === 70.0)
        );
    }

    public function test_excludes_other_businesses_products_and_locations(): void
    {
        $tenantId = 'tenant-inv-loc-mine';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Main', 'is_active' => true]);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Mine',
            'item_type' => 'product', 'price' => 10, 'cost_price' => 2, 'track_stock' => true, 'is_active' => true,
        ]);
        ProductStock::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 3]);

        $otherTenantId = 'tenant-inv-loc-other';
        Tenant::create(['id' => $otherTenantId, 'business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => '999999']);
        $otherLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Their Place', 'is_active' => true]);
        Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Theirs',
            'item_type' => 'product', 'price' => 10, 'cost_price' => 2, 'track_stock' => true, 'is_active' => true,
        ]);

        $response = $this->get('/office/reports/inventory-by-location');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('locations', fn ($locations) => collect($locations)->pluck('name')->all() === ['Main'])
            ->where('rows', fn ($rows) => collect($rows)->pluck('name')->all() === ['Mine'])
        );
    }

    public function test_hide_zero_filter_drops_products_with_no_stock_anywhere(): void
    {
        $tenantId = 'tenant-inv-loc-hidezero';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Main', 'is_active' => true]);
        $stocked = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Stocked',
            'item_type' => 'product', 'price' => 10, 'cost_price' => 2, 'track_stock' => true, 'is_active' => true,
        ]);
        ProductStock::create(['id' => (string) Str::uuid(), 'product_id' => $stocked->id, 'location_id' => $location->id, 'quantity' => 5]);

        Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'OutOfStock',
            'item_type' => 'product', 'price' => 10, 'cost_price' => 2, 'track_stock' => true, 'is_active' => true,
        ]);

        $response = $this->get('/office/reports/inventory-by-location?hide_zero=1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('rows', fn ($rows) => collect($rows)->pluck('name')->all() === ['Stocked'])
        );
    }

    public function test_category_filter_scopes_to_matching_products(): void
    {
        $tenantId = 'tenant-inv-loc-category';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Main', 'is_active' => true]);
        $category = Category::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Beverages', 'is_active' => true]);

        Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Cola', 'category_id' => $category->id,
            'item_type' => 'product', 'price' => 10, 'cost_price' => 2, 'track_stock' => true, 'is_active' => true,
        ]);
        Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Notebook',
            'item_type' => 'product', 'price' => 5, 'cost_price' => 1, 'track_stock' => true, 'is_active' => true,
        ]);

        $response = $this->get("/office/reports/inventory-by-location?category_id={$category->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('rows', fn ($rows) => collect($rows)->pluck('name')->all() === ['Cola'])
        );
    }

    public function test_export_streams_a_csv_with_a_column_pair_per_location(): void
    {
        $tenantId = 'tenant-inv-loc-export';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Main', 'is_active' => true]);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget', 'sku' => 'W-1',
            'item_type' => 'product', 'price' => 10, 'cost_price' => 3, 'track_stock' => true, 'is_active' => true,
        ]);
        ProductStock::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 7]);

        $response = $this->get('/office/reports/inventory-by-location/export');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $lines = array_filter(explode("\n", trim($response->streamedContent())));
        $rows = array_map(fn (string $line) => str_getcsv($line), $lines);

        $this->assertSame(['Product', 'SKU', 'Category', 'Main - Qty', 'Main - Value', 'Total Qty', 'Total Value'], $rows[0]);
        $this->assertSame(['Widget', 'W-1', '', '7.00', '21.00', '7.00', '21.00'], $rows[1]);
    }

    public function test_location_scoped_manager_only_sees_their_own_branch(): void
    {
        $tenantId = 'tenant-inv-loc-scoped';

        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);

        $branchA = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch A', 'is_active' => true]);
        $branchB = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'is_active' => true]);

        $manager = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'is_active' => true]);
        $manager->locations()->attach($branchA->id);

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 10, 'cost_price' => 2, 'track_stock' => true, 'is_active' => true,
        ]);
        ProductStock::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'location_id' => $branchA->id, 'quantity' => 5]);
        ProductStock::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'location_id' => $branchB->id, 'quantity' => 9]);

        $this->actingBackOfficeSession($tenantId, 'manager', $manager);

        $response = $this->get('/office/reports/inventory-by-location');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('locations', fn ($locations) => collect($locations)->pluck('name')->all() === ['Branch A'])
            ->where('rows.0.total_quantity', fn ($v) => (float) $v === 5.0)
        );
    }
}
