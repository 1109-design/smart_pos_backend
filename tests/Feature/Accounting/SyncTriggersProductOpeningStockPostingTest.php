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
 * both places an 'opening_stock' take-on figure reaches the server (a
 * brand-new product's own initial quantity via the 'products' case, and a
 * later adjustment via a plain 'stock_movements' upsert — what
 * ProductsController::applyLocationBalance() and the till's
 * CostService.adjustStock() both send) trigger
 * ProductOpeningStockPostingService, not just the service in isolation
 * (already covered by ProductOpeningStockPostingServiceTest).
 */
class SyncTriggersProductOpeningStockPostingTest extends TestCase
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

    public function test_a_new_products_own_initial_stock_quantity_posts_a_take_on_journal(): void
    {
        $tenantId = 'tenant-e2e-opening-stock-new-product';
        $token = $this->actingDeviceToken($tenantId);
        $productId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'products',
                    'uuid' => $productId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'name' => 'Sheet of glass',
                        'item_type' => 'product',
                        'price' => 40,
                        'cost_price' => 8,
                        'track_stock' => true,
                        'stock_quantity' => 10,
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();

        $journal = JournalHeader::where('source_type', 'product_opening_stock')->where('business_id', $tenantId)->first();
        $this->assertNotNull($journal, 'a GL entry should have been posted for the new product take-on');
        $this->assertSame('posted', $journal->status);

        $inventory = GlAccount::where('business_id', $tenantId)->where('code', '1200')->first();
        $this->assertSame(80.0, $inventory->balance()); // 10 units * $8
    }

    public function test_an_opening_balance_correction_via_a_plain_stock_movement_posts_a_take_on_journal(): void
    {
        $tenantId = 'tenant-e2e-opening-stock-correction';
        $token = $this->actingDeviceToken($tenantId);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 20, 'cost_price' => 5, 'track_stock' => true, 'is_active' => true,
        ]);

        // The shape ProductsController::applyLocationBalance() and the
        // till's CostService.adjustStock() both send for a later take-on
        // correction: unit_cost stamped from the product's own cost_price.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'product_id' => $product->id,
                        'type' => 'opening_stock',
                        'quantity_change' => 12,
                        'unit_cost' => 5,
                        'reason' => 'Opening balance set via BackOffice',
                        'user_id' => (string) Str::uuid(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();

        $journal = JournalHeader::where('source_type', 'product_opening_stock')->where('business_id', $tenantId)->first();
        $this->assertNotNull($journal);
        $inventory = GlAccount::where('business_id', $tenantId)->where('code', '1200')->first();
        $this->assertSame(60.0, $inventory->balance()); // 12 units * $5
    }
}
