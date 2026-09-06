<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Device;
use App\Models\GoodsReceivedVoucher;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end coverage through the real /api/v1/sync/push endpoint — proving
 * a till syncing a PO receipt (exactly what po_detail_screen.dart's
 * _ReceiveSheet actually sends) triggers GrvPostingService via
 * SyncProcessor's 'stock_movements' case, not just the service in
 * isolation (already covered by GrvPostingServiceTest).
 */
class SyncTriggersGrvPostingTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);

        $user = User::factory()->create(['email' => $tenantId.'-owner@example.com']);
        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_a_synced_po_receipt_movement_creates_a_grv_and_posts_the_gl(): void
    {
        $tenantId = 'tenant-e2e-grv';
        $token = $this->actingDeviceToken($tenantId);

        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);
        $po = PurchaseOrder::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name, 'po_number' => 'PO-0001', 'status' => 'sent',
            'total_ordered' => 100, 'total_received' => 0, 'created_by_user_id' => (string) Str::uuid(),
        ]);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 20, 'cost_price' => 0, 'track_stock' => true, 'is_active' => true,
        ]);

        // The exact shape po_detail_screen.dart's CostService.receiveStock()
        // sends for a PO-linked receipt: type='receive', reference_id=PO id.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'product_id' => $product->id,
                        'type' => 'receive',
                        'quantity_change' => 20,
                        'unit_cost' => 5,
                        'running_avg_cost' => 5,
                        'reference_id' => $po->id,
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');

        $grv = GoodsReceivedVoucher::where('purchase_order_id', $po->id)->first();
        $this->assertNotNull($grv, 'a GRV should have been synthesized from the synced receive movement');
        $this->assertSame($supplier->id, $grv->supplier_id);

        $journal = JournalHeader::where('source_type', 'grv')->where('source_id', $grv->id)->first();
        $this->assertNotNull($journal);
        $this->assertSame('posted', $journal->status);

        $inventory = GlAccount::where('business_id', $tenantId)->where('code', '1200')->first();
        $this->assertSame(100.0, $inventory->balance()); // 20 units * $5
    }

    public function test_a_synced_walk_in_receipt_movement_never_creates_a_grv(): void
    {
        $tenantId = 'tenant-e2e-grv-walkin';
        $token = $this->actingDeviceToken($tenantId);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 20, 'cost_price' => 0, 'track_stock' => true, 'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'product_id' => $product->id,
                        'type' => 'receive',
                        'quantity_change' => 20,
                        'unit_cost' => 5,
                        'reference_id' => null,
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertSame(0, GoodsReceivedVoucher::count());
    }
}
