<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Device;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end coverage through the real /api/v1/sync/push endpoint — proving
 * a till syncing an approved stock take's variance movements (exactly what
 * StockTakeReportScreen._approve on the till actually sends: one 'stocktake'
 * stock_movement per variance line) triggers StockTakePostingService via
 * SyncProcessor's 'stock_movements' case, not just the service in isolation
 * (already covered by StockTakePostingServiceTest).
 */
class SyncTriggersStockTakePostingTest extends TestCase
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

    public function test_a_synced_stocktake_shrinkage_movement_posts_stock_loss_against_inventory(): void
    {
        $tenantId = 'tenant-e2e-stocktake';
        $token = $this->actingDeviceToken($tenantId);

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 20, 'cost_price' => 6, 'track_stock' => true, 'is_active' => true,
        ]);

        // The exact shape the till's stock take approval sends: type='stocktake',
        // reference_id=the stock take's own id, running_avg_cost set from the
        // product's cost_price, unit_cost never set (CostService.adjustStock
        // never populates it for this movement type).
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'product_id' => $product->id,
                        'type' => 'stocktake',
                        'quantity_change' => -5,
                        'running_avg_cost' => 6,
                        'reason' => 'Stock take: Q3 count',
                        'reference_id' => (string) Str::uuid(),
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');

        $journal = JournalHeader::where('source_type', 'stock_take_variance')->where('business_id', $tenantId)->first();
        $this->assertNotNull($journal, 'a GL entry should have been posted for the stock take shrinkage');
        $this->assertSame('posted', $journal->status);

        $stockLoss = GlAccount::where('business_id', $tenantId)->where('code', '6050')->first();
        $this->assertSame(30.0, $stockLoss->balance()); // 5 units * $6
    }

    public function test_a_synced_walk_in_receipt_never_triggers_stock_take_posting(): void
    {
        $tenantId = 'tenant-e2e-stocktake-noop';
        $token = $this->actingDeviceToken($tenantId);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 20, 'cost_price' => 6, 'track_stock' => true, 'is_active' => true,
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
                        'type' => 'adjustment',
                        'quantity_change' => -2,
                        'running_avg_cost' => 6,
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertSame(0, JournalHeader::where('source_type', 'stock_take_variance')->count());
    }
}
