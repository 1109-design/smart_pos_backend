<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductStock;
use App\Models\ProductUnit;
use App\Models\Tenant;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = app(PricingService::class);
    }

    private function makeProduct(string $tenantId, float $price = 10, ?float $minPrice = null): Product
    {
        return Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Priced Widget',
            'item_type' => 'product',
            'price' => $price,
            'min_price' => $minPrice,
            'track_stock' => true,
            'is_active' => true,
        ]);
    }

    public function test_resolves_the_products_flat_price_with_no_overrides_or_tiers(): void
    {
        Tenant::create(['id' => 't1', 'business_name' => 't1', 'owner_email' => 't1@example.com']);
        $product = $this->makeProduct('t1', 10);

        $this->assertSame(10.0, $this->pricing->resolveUnitPrice($product));
    }

    public function test_a_location_price_override_wins_over_the_product_default(): void
    {
        Tenant::create(['id' => 't2', 'business_name' => 't2', 'owner_email' => 't2@example.com']);
        $product = $this->makeProduct('t2', 10);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => 't2', 'name' => 'Branch', 'type' => 'shop', 'is_active' => true]);
        $stock = ProductStock::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'location_id' => $location->id, 'price_override' => 8.5]);

        $this->assertSame(8.5, $this->pricing->resolveUnitPrice($product, 1, $stock));
    }

    public function test_a_quantity_tier_overrides_the_base_price_at_the_boundary(): void
    {
        Tenant::create(['id' => 't3', 'business_name' => 't3', 'owner_email' => 't3@example.com']);
        $product = $this->makeProduct('t3', 10);
        ProductPriceTier::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'min_qty' => 10, 'unit_price' => 8]);
        ProductPriceTier::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'min_qty' => 50, 'unit_price' => 6]);
        $product->load('priceTiers');

        $this->assertSame(10.0, $this->pricing->resolveUnitPrice($product, 9)); // below first tier
        $this->assertSame(8.0, $this->pricing->resolveUnitPrice($product, 10)); // exactly at boundary
        $this->assertSame(8.0, $this->pricing->resolveUnitPrice($product, 49)); // between tiers
        $this->assertSame(6.0, $this->pricing->resolveUnitPrice($product, 50)); // exactly at second boundary
        $this->assertSame(6.0, $this->pricing->resolveUnitPrice($product, 1000)); // well above
    }

    public function test_an_alternate_unit_multiplies_the_resolved_price(): void
    {
        Tenant::create(['id' => 't4', 'business_name' => 't4', 'owner_email' => 't4@example.com']);
        $product = $this->makeProduct('t4', 1); // 1 = price per each
        ProductUnit::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'unit_name' => 'each', 'conversion_factor' => 1, 'is_base_unit' => true]);
        ProductUnit::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'unit_name' => 'box', 'conversion_factor' => 100, 'is_base_unit' => false]);
        $product->load('units');

        $this->assertSame(1.0, $this->pricing->resolveUnitPrice($product, 1, null, 'each'));
        $this->assertSame(100.0, $this->pricing->resolveUnitPrice($product, 1, null, 'box'));
    }

    public function test_unit_conversion_multiplies_the_tier_price_not_the_base_price(): void
    {
        Tenant::create(['id' => 't5', 'business_name' => 't5', 'owner_email' => 't5@example.com']);
        $product = $this->makeProduct('t5', 1);
        ProductPriceTier::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'min_qty' => 200, 'unit_price' => 0.8]);
        ProductUnit::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'unit_name' => 'box', 'conversion_factor' => 100, 'is_base_unit' => false]);
        $product->load(['priceTiers', 'units']);

        // 3 boxes = 300 base units, clears the 200-unit tier (0.8/each), then
        // the box conversion multiplies that tier price, not the flat price.
        $this->assertSame(80.0, $this->pricing->resolveUnitPrice($product, 300, null, 'box'));
    }

    public function test_to_base_unit_quantity_converts_correctly(): void
    {
        Tenant::create(['id' => 't6', 'business_name' => 't6', 'owner_email' => 't6@example.com']);
        $product = $this->makeProduct('t6', 1);
        ProductUnit::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'unit_name' => 'box', 'conversion_factor' => 100, 'is_base_unit' => false]);
        $product->load('units');

        $this->assertSame(300.0, $this->pricing->toBaseUnitQuantity($product, 3, 'box'));
        $this->assertSame(3.0, $this->pricing->toBaseUnitQuantity($product, 3, null));
    }

    public function test_manual_discount_is_clamped_to_the_min_price_floor(): void
    {
        Tenant::create(['id' => 't7', 'business_name' => 't7', 'owner_email' => 't7@example.com']);
        $product = $this->makeProduct('t7', 10, minPrice: 7);

        $this->assertSame(7.0, $this->pricing->applyManualDiscount($product, 5));
        $this->assertSame(8.0, $this->pricing->applyManualDiscount($product, 8));
    }

    public function test_manual_discount_is_unclamped_when_no_min_price_is_set(): void
    {
        Tenant::create(['id' => 't8', 'business_name' => 't8', 'owner_email' => 't8@example.com']);
        $product = $this->makeProduct('t8', 10);

        $this->assertSame(1.0, $this->pricing->applyManualDiscount($product, 1));
    }

    public function test_a_tier_price_below_min_price_is_not_clamped_owner_configured_bulk_pricing(): void
    {
        Tenant::create(['id' => 't9', 'business_name' => 't9', 'owner_email' => 't9@example.com']);
        $product = $this->makeProduct('t9', 10, minPrice: 8);
        ProductPriceTier::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'min_qty' => 100, 'unit_price' => 5]);
        $product->load('priceTiers');

        $this->assertSame(5.0, $this->pricing->resolveUnitPrice($product, 100));
    }
}
