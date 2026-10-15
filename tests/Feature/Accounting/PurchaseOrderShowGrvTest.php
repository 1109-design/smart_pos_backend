<?php

namespace Tests\Feature\Accounting;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Business;
use App\Models\GoodsReceivedVoucher;
use App\Models\GrvItem;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseOrderShowGrvTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);
        Business::firstOrCreate(['id' => $tenantId], ['name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId,
            'email' => $tenantId.'-user@example.com', 'is_active' => true,
        ]);

        session(['backoffice' => [
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'user_name' => $user->name,
            'user_email' => $user->email, 'role' => 'business_owner', 'business_name' => $tenantId,
            'currency_code' => 'USD',
        ]]);

        return $user;
    }

    public function test_po_show_page_shows_no_grvs_when_nothing_received_yet(): void
    {
        $tenantId = 'tenant-po-grv-2';
        $this->actingBackOfficeSession($tenantId);

        $po = PurchaseOrder::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'po_number' => 'PO-0100',
            'status' => 'sent', 'created_by_user_id' => (string) Str::uuid(),
        ]);

        $response = $this->get("/office/purchase-orders/{$po->id}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('grvs', 0));
    }

    public function test_a_grv_left_unposted_shows_as_not_posted(): void
    {
        // A GRV can exist without a posted journal if, e.g., the chart of
        // accounts was missing Inventory/GRN Suspense at the time — the
        // page must reflect that honestly rather than assume every GRV posted.
        $tenantId = 'tenant-po-grv-3';
        $this->actingBackOfficeSession($tenantId);

        $po = PurchaseOrder::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'po_number' => 'PO-0101',
            'status' => 'sent', 'created_by_user_id' => (string) Str::uuid(),
        ]);
        $grv = GoodsReceivedVoucher::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'grv_number' => 'GRV-2026-99999',
            'purchase_order_id' => $po->id, 'received_date' => '2026-06-01',
        ]);
        GrvItem::create([
            'grv_id' => $grv->id, 'stock_movement_id' => (string) Str::uuid(),
            'product_id' => (string) Str::uuid(), 'product_name' => 'Orphaned Item',
            'quantity_received' => 1, 'quantity_accepted' => 1, 'unit_cost' => 1,
        ]);

        $response = $this->get("/office/purchase-orders/{$po->id}");
        $response->assertInertia(fn ($page) => $page
            ->where('grvs.0.posted_to_ledger', false)
        );
    }
}
