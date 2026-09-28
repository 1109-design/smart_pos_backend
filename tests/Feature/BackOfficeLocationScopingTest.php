<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockTake;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeLocationScopingTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId, User $user, string $role = 'manager'): void
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

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
    }

    /**
     * @return array{0: string, 1: Location, 2: Location, 3: User}
     */
    private function setUpTwoBranches(string $tenantId): array
    {
        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);

        $branchA = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch A', 'type' => 'shop', 'is_active' => true]);
        $branchB = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'type' => 'shop', 'is_active' => true]);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'email' => $tenantId.'-manager@example.com',
            'is_active' => true,
        ]);

        return [$tenantId, $branchA, $branchB, $user];
    }

    public function test_dashboard_hides_other_branches_revenue_for_a_scoped_manager(): void
    {
        [$tenantId, $branchA, $branchB, $manager] = $this->setUpTwoBranches('tenant-scope-dash');
        $manager->locations()->attach($branchA->id);

        Transaction::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $branchA->id,
            'status' => 'completed', 'total' => 100, 'subtotal' => 100, 'base_currency' => 'USD', 'user_id' => $manager->id,
        ]);
        Transaction::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $branchB->id,
            'status' => 'completed', 'total' => 900, 'subtotal' => 900, 'base_currency' => 'USD', 'user_id' => $manager->id,
        ]);

        $this->actingBackOfficeSession($tenantId, $manager);

        $response = $this->get('/office/dashboard');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('stats.month_revenue', 100));
    }

    public function test_dashboard_shows_all_branches_for_an_unscoped_manager(): void
    {
        [$tenantId, $branchA, $branchB, $manager] = $this->setUpTwoBranches('tenant-scope-dash-2');
        // Deliberately not scoping this manager to any location.

        Transaction::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $branchA->id,
            'status' => 'completed', 'total' => 100, 'subtotal' => 100, 'base_currency' => 'USD', 'user_id' => $manager->id,
        ]);
        Transaction::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $branchB->id,
            'status' => 'completed', 'total' => 900, 'subtotal' => 900, 'base_currency' => 'USD', 'user_id' => $manager->id,
        ]);

        $this->actingBackOfficeSession($tenantId, $manager);

        $response = $this->get('/office/dashboard');
        $response->assertInertia(fn ($page) => $page->where('stats.month_revenue', 1000));
    }

    public function test_transactions_page_excludes_other_branches_for_a_scoped_manager(): void
    {
        [$tenantId, $branchA, $branchB, $manager] = $this->setUpTwoBranches('tenant-scope-tx');
        $manager->locations()->attach($branchA->id);

        $ownSale = Transaction::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $branchA->id,
            'status' => 'completed', 'total' => 50, 'subtotal' => 50, 'base_currency' => 'USD',
            'sale_number' => 'A-1', 'user_id' => $manager->id,
        ]);
        $otherSale = Transaction::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $branchB->id,
            'status' => 'completed', 'total' => 75, 'subtotal' => 75, 'base_currency' => 'USD',
            'sale_number' => 'B-1', 'user_id' => $manager->id,
        ]);

        $this->actingBackOfficeSession($tenantId, $manager);

        $response = $this->get('/office/transactions');
        $response->assertOk();

        $ids = collect($response->viewData('page')['props']['transactions']['data'])->pluck('id');
        $this->assertTrue($ids->contains($ownSale->id));
        $this->assertFalse($ids->contains($otherSale->id));
    }

    public function test_purchase_orders_are_scoped_by_receiving_location(): void
    {
        [$tenantId, $branchA, $branchB, $manager] = $this->setUpTwoBranches('tenant-scope-po');
        $manager->locations()->attach($branchA->id);

        $ownOrder = PurchaseOrder::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'receiving_location_id' => $branchA->id,
            'po_number' => 'PO-A', 'status' => 'draft', 'total_ordered' => 100, 'total_received' => 0,
            'created_by_user_id' => $manager->id,
        ]);
        $foreignOrder = PurchaseOrder::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'receiving_location_id' => $branchB->id,
            'po_number' => 'PO-B', 'status' => 'draft', 'total_ordered' => 100, 'total_received' => 0,
            'created_by_user_id' => $manager->id,
        ]);

        $this->actingBackOfficeSession($tenantId, $manager);

        $this->get("/office/purchase-orders/{$ownOrder->id}")->assertOk();
        $this->get("/office/purchase-orders/{$foreignOrder->id}")->assertNotFound();
    }

    public function test_shifts_page_clamps_a_foreign_location_filter_back_to_own_scope(): void
    {
        [$tenantId, $branchA, $branchB, $manager] = $this->setUpTwoBranches('tenant-scope-shifts');
        $manager->locations()->attach($branchA->id);

        $this->actingBackOfficeSession($tenantId, $manager);

        // Guessing branch B's id in the URL must not surface its data.
        $response = $this->get('/office/shifts?location='.$branchB->id);
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('filters.location', 'all'));
    }

    /**
     * Regression: PurchaseOrdersController::cancel() scoped only by
     * business_id, unlike index()/show() in the same controller — a manager
     * scoped to Branch A who knew or guessed a Branch B PO id could cancel
     * it despite not being able to see it anywhere else.
     */
    public function test_purchase_order_cancel_is_blocked_for_a_po_outside_the_managers_scope(): void
    {
        [$tenantId, $branchA, $branchB, $manager] = $this->setUpTwoBranches('tenant-scope-po-cancel');
        $manager->locations()->attach($branchA->id);

        $foreignOrder = PurchaseOrder::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'receiving_location_id' => $branchB->id,
            'po_number' => 'PO-CANCEL', 'status' => 'draft', 'total_ordered' => 100, 'total_received' => 0,
            'created_by_user_id' => $manager->id,
        ]);

        $this->actingBackOfficeSession($tenantId, $manager);

        $this->post("/office/purchase-orders/{$foreignOrder->id}/cancel")->assertNotFound();
        $this->assertSame('draft', $foreignOrder->fresh()->status);
    }

    /**
     * Regression: StockTakesController had no location scoping at all —
     * only the Phase 3 permission-check swap landed, leaving every action
     * (view/approve/reject) business-wide regardless of the acting user's
     * location scope.
     */
    public function test_stock_takes_are_scoped_to_the_managers_own_locations(): void
    {
        [$tenantId, $branchA, $branchB, $manager] = $this->setUpTwoBranches('tenant-scope-stocktake');
        $manager->locations()->attach($branchA->id);

        $ownTake = StockTake::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $branchA->id,
            'title' => 'Own Take', 'status' => 'pending_approval', 'created_by_user_id' => $manager->id,
        ]);
        $foreignTake = StockTake::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $branchB->id,
            'title' => 'Foreign Take', 'status' => 'pending_approval', 'created_by_user_id' => $manager->id,
        ]);

        $this->actingBackOfficeSession($tenantId, $manager);

        $this->get('/office/stocktakes')->assertInertia(fn ($page) => $page
            ->has('stock_takes.data', 1)
            ->where('stock_takes.data.0.id', $ownTake->id)
        );
        $this->get("/office/stocktakes/{$ownTake->id}")->assertOk();
        $this->get("/office/stocktakes/{$foreignTake->id}")->assertNotFound();
        $this->post("/office/stocktakes/{$foreignTake->id}/approve")->assertNotFound();
        $this->assertSame('pending_approval', $foreignTake->fresh()->status);
    }

    /**
     * Regression: Storeman's stock-health/suggestions overview and its
     * transfer-request action only checked tenant ownership, never the
     * acting user's location scope — a manager restricted to Branch A could
     * see health data for every branch and request a transfer entirely
     * between two locations outside their scope.
     */
    public function test_storeman_health_and_suggestions_are_scoped_to_the_managers_own_locations(): void
    {
        [$tenantId, $branchA, $branchB, $manager] = $this->setUpTwoBranches('tenant-scope-storeman');
        $manager->locations()->attach($branchA->id);

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 5, 'track_stock' => true, 'low_stock_threshold' => 10,
        ]);
        app(SyncProcessor::class)->process('stock_movements', (string) Str::uuid(), 'upsert', [
            'business_id' => $tenantId, 'location_id' => $branchB->id, 'product_id' => $product->id,
            'type' => 'opening_stock', 'quantity_change' => 3, 'reason' => 'Test',
        ]);

        $this->actingBackOfficeSession($tenantId, $manager);

        $response = $this->get('/office/storeman');
        $response->assertInertia(fn ($page) => $page
            ->has('stock_health', 1)
            ->where('stock_health.0.id', $branchA->id)
        );

        // Requesting a transfer entirely outside their scope is refused.
        $this->post('/office/storeman/suggested-transfer', [
            'product_id' => $product->id,
            'from_location_id' => $branchB->id,
            'to_location_id' => $branchA->id,
            'qty' => 1,
        ])->assertNotFound();
    }
}
