<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeQuotationStockTest extends TestCase
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

    public function test_flags_a_shortfall_when_quoted_quantity_exceeds_current_stock(): void
    {
        $tenantId = 'tenant-quo-stock-1';
        $this->actingBackOfficeSession($tenantId);

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 10, 'track_stock' => true, 'stock_quantity' => 3,
        ]);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Co']);

        $quote = Quotation::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'customer_id' => $customer->id,
            'quote_number' => 'QUO-1', 'status' => 'sent', 'created_by_user_id' => (string) Str::uuid(),
        ]);
        QuotationItem::create([
            'id' => (string) Str::uuid(), 'quotation_id' => $quote->id, 'product_id' => $product->id,
            'product_name' => 'Widget', 'quantity' => 8, 'unit_price' => 10, 'line_total' => 80,
        ]);

        $response = $this->get('/office/reports/quoted-vs-stock');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('BackOffice/QuotationStock')
            ->has('rows', 1)
            ->where('rows.0.quote_number', 'QUO-1')
            ->where('rows.0.customer_name', 'Acme Co')
            ->where('rows.0.quantity_quoted', 8)
            ->where('rows.0.available_now', 3)
            ->where('rows.0.shortfall', 5)
        );
    }

    public function test_no_shortfall_when_stock_covers_the_quote(): void
    {
        $tenantId = 'tenant-quo-stock-2';
        $this->actingBackOfficeSession($tenantId);

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 10, 'track_stock' => true, 'stock_quantity' => 20,
        ]);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Walk-in']);
        $quote = Quotation::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'customer_id' => $customer->id,
            'quote_number' => 'QUO-2', 'status' => 'draft', 'created_by_user_id' => (string) Str::uuid(),
        ]);
        QuotationItem::create([
            'id' => (string) Str::uuid(), 'quotation_id' => $quote->id, 'product_id' => $product->id,
            'product_name' => 'Widget', 'quantity' => 5, 'unit_price' => 10, 'line_total' => 50,
        ]);

        $response = $this->get('/office/reports/quoted-vs-stock');

        $response->assertInertia(fn ($page) => $page
            ->where('rows.0.shortfall', 0)
            ->where('rows.0.customer_name', 'Walk-in')
        );
    }

    public function test_uses_location_specific_stock_when_the_quote_has_a_location(): void
    {
        $tenantId = 'tenant-quo-stock-3';
        $this->actingBackOfficeSession($tenantId);

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 10, 'track_stock' => true, 'stock_quantity' => 50,
        ]);
        $locationId = (string) Str::uuid();
        ProductStock::create([
            'id' => (string) Str::uuid(), 'product_id' => $product->id, 'location_id' => $locationId, 'quantity' => 2,
        ]);

        $quote = Quotation::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $locationId, 'customer_id' => (string) Str::uuid(),
            'quote_number' => 'QUO-3', 'status' => 'sent', 'created_by_user_id' => (string) Str::uuid(),
        ]);
        QuotationItem::create([
            'id' => (string) Str::uuid(), 'quotation_id' => $quote->id, 'product_id' => $product->id,
            'product_name' => 'Widget', 'quantity' => 5, 'unit_price' => 10, 'line_total' => 50,
        ]);

        // The flat cross-location total (50) would show no shortfall — the
        // report must use the location-specific row (2) instead.
        $response = $this->get('/office/reports/quoted-vs-stock');

        $response->assertInertia(fn ($page) => $page
            ->where('rows.0.available_now', 2)
            ->where('rows.0.shortfall', 3)
        );
    }

    public function test_accepted_and_rejected_quotations_are_excluded(): void
    {
        $tenantId = 'tenant-quo-stock-4';
        $this->actingBackOfficeSession($tenantId);

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 10, 'track_stock' => true, 'stock_quantity' => 1,
        ]);

        foreach (['accepted', 'rejected', 'expired', 'converted'] as $status) {
            $quote = Quotation::create([
                'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'customer_id' => (string) Str::uuid(),
                'quote_number' => "QUO-$status", 'status' => $status, 'created_by_user_id' => (string) Str::uuid(),
            ]);
            QuotationItem::create([
                'id' => (string) Str::uuid(), 'quotation_id' => $quote->id, 'product_id' => $product->id,
                'product_name' => 'Widget', 'quantity' => 10, 'unit_price' => 10, 'line_total' => 100,
            ]);
        }

        $response = $this->get('/office/reports/quoted-vs-stock');

        $response->assertInertia(fn ($page) => $page->has('rows', 0));
    }

    public function test_scoped_to_the_current_tenant(): void
    {
        $otherTenantId = 'tenant-quo-stock-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => 'AAAAAA']);
        $foreignProduct = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Their Widget',
            'item_type' => 'product', 'price' => 10, 'track_stock' => true, 'stock_quantity' => 0,
        ]);
        $foreignQuote = Quotation::create([
            'id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'customer_id' => (string) Str::uuid(),
            'quote_number' => 'QUO-FOREIGN', 'status' => 'sent', 'created_by_user_id' => (string) Str::uuid(),
        ]);
        QuotationItem::create([
            'id' => (string) Str::uuid(), 'quotation_id' => $foreignQuote->id, 'product_id' => $foreignProduct->id,
            'product_name' => 'Their Widget', 'quantity' => 10, 'unit_price' => 10, 'line_total' => 100,
        ]);

        $tenantId = 'tenant-quo-stock-5';
        $this->actingBackOfficeSession($tenantId);

        $response = $this->get('/office/reports/quoted-vs-stock');

        $response->assertInertia(fn ($page) => $page->has('rows', 0));
    }
}
