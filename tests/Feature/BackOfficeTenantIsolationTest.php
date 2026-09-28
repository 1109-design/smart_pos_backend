<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Bundle;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockTake;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every BackOffice index page must exclude other businesses' data. There is
 * no global-scope safety net for this (see BackOfficeController and the
 * Tenant model docblock) — each controller filters manually, which is
 * exactly the class of bug that leaked Dashboard/Reports/Transactions data
 * across businesses (fixed 2026-08-17, see BackOfficeDashboardTest et al.).
 * This file generalizes that regression test across the remaining index
 * pages instead of trusting each controller to remember the filter, by
 * checking the raw response body rather than a page-specific Inertia prop
 * path — any leaked marker fails the test regardless of where in the
 * response shape it would have surfaced.
 */
class BackOfficeTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::create([
            'id' => $tenantId,
            'business_name' => $tenantId,
            'owner_email' => $tenantId.'@example.com',
            'pairing_code' => strtoupper(Str::random(6)),
        ]);

        $user = User::factory()->create([
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
                'role' => 'business_owner',
                'business_name' => $tenantId,
                'currency_code' => 'USD',
            ],
        ]);

        return $user;
    }

    private function otherBusiness(string $tenantId): User
    {
        Tenant::create([
            'id' => $tenantId,
            'business_name' => $tenantId,
            'owner_email' => $tenantId.'@example.com',
            'pairing_code' => strtoupper(Str::random(6)),
        ]);

        return User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'email' => $tenantId.'-owner@example.com',
            'is_active' => true,
        ]);
    }

    /**
     * Seeds a "mine" row and an "other business" row via the given closures
     * (each receives (tenantId, ownerUser, marker) and must persist a record
     * containing that marker somewhere renderable), hits the index route as
     * "mine", and asserts the marker for "mine" is present while the other
     * business's marker never appears anywhere in the response body.
     */
    private function assertIndexIsolatesBusiness(string $routeName, Closure $seed): void
    {
        $mineTenant = 'biz-mine-'.Str::lower(Str::random(8));
        $otherTenant = 'biz-other-'.Str::lower(Str::random(8));

        $mineMarker = 'MINEMARK'.Str::upper(Str::random(10));
        $otherMarker = 'OTHERMARK'.Str::upper(Str::random(10));

        $owner = $this->actingBackOfficeSession($mineTenant);
        $otherOwner = $this->otherBusiness($otherTenant);

        $seed($mineTenant, $owner, $mineMarker);
        $seed($otherTenant, $otherOwner, $otherMarker);

        $response = $this->get(route($routeName));

        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString($mineMarker, $body, "Expected {$routeName} to render the caller's own data");
        $this->assertStringNotContainsString($otherMarker, $body, "{$routeName} leaked another business's data");
    }

    public function test_suppliers_index_excludes_other_business(): void
    {
        $this->assertIndexIsolatesBusiness('office.suppliers.index', function (string $tenantId, User $owner, string $marker) {
            Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => $marker]);
        });
    }

    public function test_customers_index_excludes_other_business(): void
    {
        $this->assertIndexIsolatesBusiness('office.customers.index', function (string $tenantId, User $owner, string $marker) {
            Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => $marker]);
        });
    }

    public function test_products_index_excludes_other_business(): void
    {
        $this->assertIndexIsolatesBusiness('office.products.index', function (string $tenantId, User $owner, string $marker) {
            Product::create([
                'id' => (string) Str::uuid(),
                'business_id' => $tenantId,
                'name' => $marker,
                'price' => 9.99,
            ]);
        });
    }

    public function test_combos_index_excludes_other_business(): void
    {
        $this->assertIndexIsolatesBusiness('office.combos.index', function (string $tenantId, User $owner, string $marker) {
            Bundle::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => $marker]);
        });
    }

    public function test_locations_index_excludes_other_business(): void
    {
        $this->assertIndexIsolatesBusiness('office.locations.index', function (string $tenantId, User $owner, string $marker) {
            Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => $marker]);
        });
    }

    public function test_tills_index_excludes_other_business(): void
    {
        $this->assertIndexIsolatesBusiness('office.tills.index', function (string $tenantId, User $owner, string $marker) {
            $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => "Shop {$tenantId}"]);

            Till::create([
                'id' => (string) Str::uuid(),
                'business_id' => $tenantId,
                'location_id' => $location->id,
                'name' => $marker,
                'register_number' => 1,
            ]);
        });
    }

    public function test_transfers_index_excludes_other_business(): void
    {
        $this->assertIndexIsolatesBusiness('office.transfers.index', function (string $tenantId, User $owner, string $marker) {
            $from = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => "Warehouse {$tenantId}"]);
            $to = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => "Shop {$tenantId}"]);

            StockTransfer::create([
                'id' => (string) Str::uuid(),
                'business_id' => $tenantId,
                'transfer_number' => $marker,
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'requested_by_user_id' => $owner->id,
            ]);
        });
    }

    public function test_purchase_orders_index_excludes_other_business(): void
    {
        $this->assertIndexIsolatesBusiness('office.purchase-orders.index', function (string $tenantId, User $owner, string $marker) {
            PurchaseOrder::create([
                'id' => (string) Str::uuid(),
                'business_id' => $tenantId,
                'po_number' => $marker,
                'created_by_user_id' => $owner->id,
            ]);
        });
    }

    public function test_stocktakes_index_excludes_other_business(): void
    {
        $this->assertIndexIsolatesBusiness('office.stocktakes.index', function (string $tenantId, User $owner, string $marker) {
            StockTake::create([
                'id' => (string) Str::uuid(),
                'business_id' => $tenantId,
                'title' => $marker,
                'created_by_user_id' => $owner->id,
            ]);
        });
    }
}
