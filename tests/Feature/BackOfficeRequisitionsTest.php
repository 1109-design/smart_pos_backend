<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\BackOfficeRolePermission;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SyncProcessor;
use App\Support\BackOfficePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeRequisitionsTest extends TestCase
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

    private function makeLocation(string $tenantId, string $name = 'Warehouse'): Location
    {
        return Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => $name,
            'type' => 'warehouse',
            'is_active' => true,
        ]);
    }

    private function seedLocationStock(string $tenantId, string $productId, string $locationId, float $qty): void
    {
        app(SyncProcessor::class)->process('stock_movements', (string) Str::uuid(), 'upsert', [
            'business_id' => $tenantId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => $qty,
            'reason' => 'Test opening stock',
        ]);
    }

    private function stockAt(string $productId, string $locationId): ?ProductStock
    {
        return ProductStock::where('product_id', $productId)->where('location_id', $locationId)->first();
    }

    public function test_full_lifecycle_from_request_to_issue_moves_stock(): void
    {
        $tenantId = 'tenant-req-1';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = $this->makeLocation($tenantId);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Cement Bag',
            'item_type' => 'product', 'price' => 8, 'track_stock' => true, 'stock_quantity' => 50,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 50);

        $project = Project::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Foundation Job',
            'status' => 'active', 'created_by_user_id' => (string) Str::uuid(),
        ]);

        $this->post('/office/requisitions', [
            'location_id' => $warehouse->id,
            'purpose' => 'project',
            'project_id' => $project->id,
            'notes' => 'Foundation pour',
            'items' => [['product_id' => $product->id, 'quantity_requested' => 20]],
        ])->assertRedirect();

        $requisition = Requisition::where('business_id', $tenantId)->first();
        $this->assertNotNull($requisition);
        $this->assertSame('pending', $requisition->status);
        $this->assertSame('project', $requisition->purpose);
        $this->assertSame($project->id, $requisition->project_id);
        $item = $requisition->items->first();
        $this->assertSame('20.0000', $item->quantity_requested);

        $this->assertDatabaseHas('sync_records', ['table_name' => 'requisitions', 'record_uuid' => $requisition->id]);
        $this->assertDatabaseHas('sync_records', ['table_name' => 'requisition_items', 'record_uuid' => $item->id, 'business_id' => $tenantId]);

        $this->post("/office/requisitions/{$requisition->id}/approve")->assertRedirect();
        $requisition->refresh();
        $this->assertSame('approved', $requisition->status);
        $this->assertNotNull($requisition->approved_by_user_id);

        // Not yet issued — stock untouched.
        $this->assertSame('50.0000', $this->stockAt($product->id, $warehouse->id)->quantity);

        $this->post("/office/requisitions/{$requisition->id}/issue", [
            'items' => [['item_id' => $item->id, 'quantity_issued' => 20]],
        ])->assertRedirect();

        $requisition->refresh();
        $this->assertSame('issued', $requisition->status);
        $this->assertNotNull($requisition->issued_by_user_id);
        $this->assertSame('20.0000', $requisition->items->first()->quantity_issued);

        $this->assertSame('30.0000', $this->stockAt($product->id, $warehouse->id)->quantity);
        $this->assertSame('30.0000', $product->fresh()->stock_quantity);

        $this->assertDatabaseHas('stock_movements', [
            'reference_id' => $requisition->id,
            'type' => 'requisition_issue',
            'location_id' => $warehouse->id,
            'quantity_change' => -20,
        ]);
    }

    public function test_partial_issue_only_moves_what_was_actually_handed_over(): void
    {
        $tenantId = 'tenant-req-2';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = $this->makeLocation($tenantId);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Timber Plank',
            'item_type' => 'product', 'price' => 5, 'track_stock' => true, 'stock_quantity' => 10,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 10);

        $this->post('/office/requisitions', [
            'location_id' => $warehouse->id,
            'purpose' => 'general',
            'items' => [['product_id' => $product->id, 'quantity_requested' => 10]],
        ]);
        $requisition = Requisition::where('business_id', $tenantId)->first();
        $item = $requisition->items->first();
        $this->post("/office/requisitions/{$requisition->id}/approve");

        $this->post("/office/requisitions/{$requisition->id}/issue", [
            'items' => [['item_id' => $item->id, 'quantity_issued' => 6]],
        ])->assertRedirect();

        $this->assertSame('4.0000', $this->stockAt($product->id, $warehouse->id)->quantity);
        $this->assertSame('6.0000', $requisition->fresh()->items->first()->quantity_issued);
    }

    public function test_cannot_issue_more_than_was_requested(): void
    {
        $tenantId = 'tenant-req-3';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = $this->makeLocation($tenantId);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Nails',
            'item_type' => 'product', 'price' => 1, 'track_stock' => true, 'stock_quantity' => 100,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 100);

        $this->post('/office/requisitions', [
            'location_id' => $warehouse->id,
            'purpose' => 'general',
            'items' => [['product_id' => $product->id, 'quantity_requested' => 5]],
        ]);
        $requisition = Requisition::where('business_id', $tenantId)->first();
        $item = $requisition->items->first();
        $this->post("/office/requisitions/{$requisition->id}/approve");

        $this->post("/office/requisitions/{$requisition->id}/issue", [
            'items' => [['item_id' => $item->id, 'quantity_issued' => 50]],
        ])->assertRedirect();

        // Clamped to the 5 actually requested, not the 50 sent.
        $this->assertSame('95.0000', $this->stockAt($product->id, $warehouse->id)->quantity);
        $this->assertSame('5.0000', $requisition->fresh()->items->first()->quantity_issued);
    }

    public function test_cannot_issue_a_requisition_that_is_still_pending(): void
    {
        $tenantId = 'tenant-req-4';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = $this->makeLocation($tenantId);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Screws',
            'item_type' => 'product', 'price' => 1, 'track_stock' => true, 'stock_quantity' => 10,
        ]);
        $this->post('/office/requisitions', [
            'location_id' => $warehouse->id,
            'purpose' => 'general',
            'items' => [['product_id' => $product->id, 'quantity_requested' => 5]],
        ]);
        $requisition = Requisition::where('business_id', $tenantId)->first();
        $item = $requisition->items->first();

        $this->post("/office/requisitions/{$requisition->id}/issue", [
            'items' => [['item_id' => $item->id, 'quantity_issued' => 5]],
        ])->assertSessionHasErrors('requisition');

        $this->assertSame('pending', $requisition->fresh()->status);
    }

    public function test_reject_and_cancel_paths(): void
    {
        $tenantId = 'tenant-req-5';
        $this->actingBackOfficeSession($tenantId);

        $warehouse = $this->makeLocation($tenantId);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Paint',
            'item_type' => 'product', 'price' => 1, 'track_stock' => true, 'stock_quantity' => 10,
        ]);

        $this->post('/office/requisitions', [
            'location_id' => $warehouse->id, 'purpose' => 'general',
            'items' => [['product_id' => $product->id, 'quantity_requested' => 2]],
        ]);
        $rejected = Requisition::where('business_id', $tenantId)->first();
        $this->post("/office/requisitions/{$rejected->id}/reject")->assertRedirect();
        $this->assertSame('rejected', $rejected->fresh()->status);

        // A rejected requisition can no longer be approved.
        $this->post("/office/requisitions/{$rejected->id}/approve")->assertSessionHasErrors('requisition');

        $this->post('/office/requisitions', [
            'location_id' => $warehouse->id, 'purpose' => 'general',
            'items' => [['product_id' => $product->id, 'quantity_requested' => 3]],
        ]);
        $cancelled = Requisition::where('business_id', $tenantId)->where('id', '!=', $rejected->id)->first();
        $this->post("/office/requisitions/{$cancelled->id}/approve");
        $this->post("/office/requisitions/{$cancelled->id}/cancel")->assertRedirect();
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_issuing_requires_the_storeman_permission_even_for_a_manager(): void
    {
        $tenantId = 'tenant-req-6';
        $manager = $this->actingBackOfficeSession($tenantId, role: 'manager');

        // Explicitly strip MANAGE_STOREMAN from this manager's custom
        // permission set, leaving MANAGE_REQUISITIONS — proves the two
        // gates are independent, matching the spec's distinct "who
        // approves" vs "who issues" roles.
        BackOfficeRolePermission::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'role' => 'manager',
            'permissions_json' => [BackOfficePermission::MANAGE_REQUISITIONS],
        ]);

        $warehouse = $this->makeLocation($tenantId);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Bricks',
            'item_type' => 'product', 'price' => 1, 'track_stock' => true, 'stock_quantity' => 100,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 100);

        $this->post('/office/requisitions', [
            'location_id' => $warehouse->id, 'purpose' => 'general',
            'items' => [['product_id' => $product->id, 'quantity_requested' => 10]],
        ])->assertRedirect();
        $requisition = Requisition::where('business_id', $tenantId)->first();
        $item = $requisition->items->first();

        $this->post("/office/requisitions/{$requisition->id}/approve")->assertRedirect();

        $this->post("/office/requisitions/{$requisition->id}/issue", [
            'items' => [['item_id' => $item->id, 'quantity_issued' => 10]],
        ])->assertForbidden();

        $this->assertSame('approved', $requisition->fresh()->status);
        $this->assertSame('100.0000', $this->stockAt($product->id, $warehouse->id)->quantity);
    }

    public function test_actions_are_scoped_to_the_current_tenant(): void
    {
        $otherTenantId = 'tenant-req-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => 'BBBBBB']);
        $foreignWarehouse = $this->makeLocation($otherTenantId, 'Their Warehouse');
        $foreignProduct = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Their Product',
            'item_type' => 'product', 'price' => 1, 'track_stock' => true, 'stock_quantity' => 5,
        ]);

        $tenantId = 'tenant-req-7';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/requisitions', [
            'location_id' => $foreignWarehouse->id, 'purpose' => 'general',
            'items' => [['product_id' => $foreignProduct->id, 'quantity_requested' => 1]],
        ])->assertNotFound();

        $foreignRequisition = Requisition::create([
            'id' => (string) Str::uuid(), 'business_id' => $otherTenantId,
            'requisition_number' => 'REQ-FOREIGN-001', 'location_id' => $foreignWarehouse->id,
            'status' => 'pending', 'requested_by_user_id' => (string) Str::uuid(),
        ]);

        $this->post("/office/requisitions/{$foreignRequisition->id}/approve")->assertNotFound();
        $this->assertSame('pending', $foreignRequisition->fresh()->status);
    }

    public function test_index_exposes_active_and_closed_projects_for_selection_and_display(): void
    {
        $tenantId = 'tenant-req-8';
        $this->actingBackOfficeSession($tenantId);

        $active = Project::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Active Job',
            'status' => 'active', 'created_by_user_id' => (string) Str::uuid(),
        ]);
        Project::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Closed Job',
            'status' => 'closed', 'created_by_user_id' => (string) Str::uuid(),
        ]);

        $response = $this->get('/office/requisitions');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('projects', 2));
    }

    public function test_a_project_requisition_validates_against_a_real_project(): void
    {
        $tenantId = 'tenant-req-9';
        $this->actingBackOfficeSession($tenantId);
        $warehouse = $this->makeLocation($tenantId);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Cement',
            'item_type' => 'product', 'price' => 10, 'track_stock' => true, 'stock_quantity' => 50,
        ]);
        $this->seedLocationStock($tenantId, $product->id, $warehouse->id, 50);

        $project = Project::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Real Job',
            'status' => 'active', 'created_by_user_id' => (string) Str::uuid(),
        ]);

        $this->post('/office/requisitions', [
            'location_id' => $warehouse->id, 'purpose' => 'project', 'project_id' => $project->id,
            'items' => [['product_id' => $product->id, 'quantity_requested' => 5]],
        ])->assertRedirect();

        $this->assertDatabaseHas('requisitions', ['business_id' => $tenantId, 'project_id' => $project->id]);

        $this->post('/office/requisitions', [
            'location_id' => $warehouse->id, 'purpose' => 'project', 'project_id' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity_requested' => 5]],
        ])->assertStatus(422);
    }
}
