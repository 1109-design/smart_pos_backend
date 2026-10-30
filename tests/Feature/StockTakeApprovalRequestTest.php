<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\ApprovalRuleSet;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\SyncProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A stock take submitted under a configured 'stock_take' approval rule
 * carries a 'stock_take_approval' request: deciding it from the Approvals
 * inbox must apply (or reject) the take, and deciding the take on its own
 * BackOffice screen must close the request.
 */
class StockTakeApprovalRequestTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $businessId = (string) Str::uuid();
        Tenant::create(['id' => $businessId, 'business_name' => $businessId, 'owner_email' => $businessId.'@example.com']);

        $this->seed(RolesAndPermissionsSeeder::class);
        $counter = User::factory()->create(['business_id' => $businessId, 'email' => Str::random(10).'@x.com', 'is_active' => true]);
        $counter->assignRole('cashier');
        $manager = User::factory()->create(['business_id' => $businessId, 'email' => Str::random(10).'@x.com', 'is_active' => true]);
        $manager->assignRole('manager');

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Main Store', 'type' => 'shop', 'is_active' => true]);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Paint 5L',
            'item_type' => 'product', 'price' => 5, 'track_stock' => true, 'stock_quantity' => 10,
        ]);
        app(SyncProcessor::class)->process('stock_movements', (string) Str::uuid(), 'upsert', [
            'business_id' => $businessId, 'location_id' => $location->id, 'product_id' => $product->id,
            'type' => 'opening_stock', 'quantity_change' => 10, 'reason' => 'Opening',
        ]);

        $take = StockTake::create([
            'id' => (string) Str::uuid(), 'business_id' => $businessId, 'location_id' => $location->id,
            'title' => 'Shelf A', 'status' => 'pending_approval', 'created_by_user_id' => $counter->id,
        ]);
        StockTakeItem::create([
            'id' => (string) Str::uuid(), 'stock_take_id' => $take->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'system_qty' => 10, 'counted_qty' => 8,
        ]);

        $ruleSet = ApprovalRuleSet::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'process' => 'stock_take', 'name' => 'Stock Takes', 'is_enabled' => true]);
        ApprovalRule::create([
            'id' => (string) Str::uuid(), 'business_id' => $businessId, 'rule_set_id' => $ruleSet->id,
            'level' => 1, 'condition_type' => 'always', 'required_role' => 'manager', 'sla_hours' => 24,
        ]);

        $request = app(ApprovalService::class)->request(
            $businessId, 'StockTake', $take->id, 'stock_take_approval', $counter->id,
            ['title' => 'Shelf A'], process: 'stock_take', context: ['amount' => 10.0],
        );

        return compact('businessId', 'counter', 'manager', 'location', 'product', 'take', 'request');
    }

    public function test_approving_the_request_applies_the_stock_take(): void
    {
        ['manager' => $manager, 'location' => $location, 'product' => $product, 'take' => $take, 'request' => $request] = $this->scenario();

        app(ApprovalService::class)->resolve($request->id, $manager->id, 'approved');

        $take->refresh();
        $this->assertSame('approved', $take->status);
        $this->assertSame($manager->id, $take->approved_by_user_id);

        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertSame(8.0, (float) $stock->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'reference_id' => $take->id, 'type' => 'stocktake', 'quantity_change' => -2,
        ]);
    }

    public function test_rejecting_the_request_rejects_the_take_without_moving_stock(): void
    {
        ['manager' => $manager, 'take' => $take, 'request' => $request] = $this->scenario();

        app(ApprovalService::class)->resolve($request->id, $manager->id, 'rejected', 'Recount shelf A');

        $take->refresh();
        $this->assertSame('rejected', $take->status);
        $this->assertSame('Recount shelf A', $take->review_comment);
        $this->assertDatabaseMissing('stock_movements', ['reference_id' => $take->id]);
    }

    public function test_the_counter_cannot_approve_their_own_stock_take_request(): void
    {
        ['counter' => $counter, 'request' => $request] = $this->scenario();

        $this->expectException(\RuntimeException::class);
        app(ApprovalService::class)->resolve($request->id, $counter->id, 'approved');
    }

    public function test_deciding_the_take_in_backoffice_closes_its_inbox_request(): void
    {
        ['businessId' => $businessId, 'manager' => $manager, 'take' => $take, 'request' => $request] = $this->scenario();

        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);
        session(['backoffice' => [
            'tenant_id' => $businessId, 'user_id' => $manager->id, 'user_name' => $manager->name,
            'user_email' => $manager->email, 'role' => 'business_owner', 'business_name' => $businessId,
            'currency_code' => 'USD',
        ]]);

        $this->post("/office/stocktakes/{$take->id}/approve")->assertRedirect();

        $this->assertSame('approved', $take->fresh()->status);
        $closed = ApprovalRequest::find($request->id);
        $this->assertSame('approved', $closed->status);
        $this->assertSame($manager->id, $closed->approver_user_id);
    }
}
