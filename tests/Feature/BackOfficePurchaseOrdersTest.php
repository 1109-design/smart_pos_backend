<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficePurchaseOrdersTest extends TestCase
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

    private function makePurchaseOrder(string $tenantId, string $status = 'draft'): PurchaseOrder
    {
        return PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'po_number' => 'PO-'.strtoupper(Str::random(5)),
            'status' => $status,
            'created_by_user_id' => (string) Str::uuid(),
        ]);
    }

    public function test_index_lists_only_the_current_tenants_orders(): void
    {
        $tenantId = 'tenant-po-1';
        $this->actingBackOfficeSession($tenantId);

        $mine = $this->makePurchaseOrder($tenantId);
        $theirs = $this->makePurchaseOrder('tenant-po-other');

        $response = $this->get('/office/purchase-orders');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $mine->id)
        );
    }

    public function test_cancel_a_draft_order(): void
    {
        $tenantId = 'tenant-po-2';
        $this->actingBackOfficeSession($tenantId);
        $order = $this->makePurchaseOrder($tenantId, 'draft');

        $this->post("/office/purchase-orders/{$order->id}/cancel")->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseHas('sync_records', ['table_name' => 'purchase_orders', 'record_uuid' => $order->id]);
        $this->assertDatabaseHas('po_audit_logs', ['po_id' => $order->id, 'action' => 'cancelled']);
    }

    public function test_a_received_order_cannot_be_cancelled_from_here(): void
    {
        $tenantId = 'tenant-po-3';
        $this->actingBackOfficeSession($tenantId);
        $order = $this->makePurchaseOrder($tenantId, 'received');

        $this->post("/office/purchase-orders/{$order->id}/cancel")->assertStatus(422);
        $this->assertSame('received', $order->fresh()->status);
    }

    public function test_cashier_cannot_view_or_cancel_purchase_orders(): void
    {
        $tenantId = 'tenant-po-4';
        $this->actingBackOfficeSession($tenantId, 'cashier');
        $order = $this->makePurchaseOrder($tenantId);

        $this->get('/office/purchase-orders')->assertForbidden();
        $this->post("/office/purchase-orders/{$order->id}/cancel")->assertForbidden();
    }

    public function test_purchase_orders_are_scoped_to_the_current_tenant(): void
    {
        $foreignOrder = $this->makePurchaseOrder('tenant-po-other-2');

        $tenantId = 'tenant-po-5';
        $this->actingBackOfficeSession($tenantId);

        $this->get("/office/purchase-orders/{$foreignOrder->id}")->assertNotFound();
        $this->post("/office/purchase-orders/{$foreignOrder->id}/cancel")->assertNotFound();
        $this->assertSame('draft', $foreignOrder->fresh()->status);
    }
}
