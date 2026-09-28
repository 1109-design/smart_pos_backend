<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeReportsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The BackOffice auth middleware calls tenancy()->initialize(), which
     * re-points the central DB connection in a way that clashes with
     * RefreshDatabase's in-memory test database. The controller under test is
     * single-DB and only reads the backoffice session, so bypass the
     * middleware and set the session directly.
     */
    private function actingBackOfficeSession(string $tenantId): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => '123456']);

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

    public function test_reports_summary_excludes_other_businesses(): void
    {
        $tenantId = 'tenant-reports-mine';
        $user = $this->actingBackOfficeSession($tenantId);

        Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'user_id' => $user->id,
            'subtotal' => 10,
            'total' => 10,
            'tax_total' => 0,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => '202608-MINE-1',
            'created_at' => now(),
        ]);

        $otherTenantId = 'tenant-reports-other';
        Tenant::create(['id' => $otherTenantId, 'business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => '654321']);
        $otherUser = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $otherTenantId]);

        Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => $otherTenantId,
            'user_id' => $otherUser->id,
            'subtotal' => 5000,
            'total' => 5000,
            'tax_total' => 0,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => '202608-OTHER-1',
            'created_at' => now(),
        ]);

        $response = $this->get('/office/reports');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('BackOffice/Reports')
            ->where('summary.total_transactions', fn ($value) => (int) $value === 1)
            ->where('summary.gross_revenue', fn ($value) => (float) $value === 10.0)
        );
    }

    public function test_reports_summary_includes_cogs_gross_profit_returns_and_voids(): void
    {
        $tenantId = 'tenant-reports-cogs';
        $user = $this->actingBackOfficeSession($tenantId);

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 20, 'cost_price' => 12, 'track_stock' => true, 'stock_quantity' => 100,
        ]);

        // A completed sale: revenue 20, cost 12 (COGS), so gross profit 8.
        $completedTxId = (string) Str::uuid();
        Transaction::create([
            'id' => $completedTxId, 'business_id' => $tenantId, 'user_id' => $user->id,
            'subtotal' => 20, 'total' => 20, 'tax_total' => 0, 'discount_total' => 0,
            'base_currency' => 'USD', 'status' => 'completed', 'sale_number' => 'S-1', 'created_at' => now(),
        ]);
        TransactionItem::create([
            'id' => (string) Str::uuid(), 'transaction_id' => $completedTxId, 'product_id' => $product->id,
            'product_name' => 'Widget', 'quantity' => 1, 'unit_price' => 20, 'line_total' => 20,
        ]);
        StockMovement::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'product_id' => $product->id,
            'type' => 'sale', 'quantity_change' => -1, 'unit_cost' => 12, 'running_avg_cost' => 12,
            'reference_id' => $completedTxId,
        ]);

        // A voided sale — must be excluded from gross_revenue but counted separately.
        Transaction::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'user_id' => $user->id,
            'subtotal' => 15, 'total' => 15, 'tax_total' => 0, 'discount_total' => 0,
            'base_currency' => 'USD', 'status' => 'voided', 'sale_number' => 'S-2', 'created_at' => now(),
        ]);

        // A refund pair: original (flipped to 'refunded', original positive
        // total untouched) + a compensating negative-total row. Only the
        // compensating row should count toward returns_total.
        $refundedOriginalId = (string) Str::uuid();
        Transaction::create([
            'id' => $refundedOriginalId, 'business_id' => $tenantId, 'user_id' => $user->id,
            'subtotal' => 25, 'total' => 25, 'tax_total' => 0, 'discount_total' => 0,
            'base_currency' => 'USD', 'status' => 'refunded', 'sale_number' => 'S-3', 'created_at' => now(),
        ]);
        Transaction::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'user_id' => $user->id,
            'subtotal' => -25, 'total' => -25, 'tax_total' => 0, 'discount_total' => 0,
            'base_currency' => 'USD', 'status' => 'refunded', 'sale_number' => 'REF-S-3', 'created_at' => now(),
        ]);

        $response = $this->get('/office/reports');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('BackOffice/Reports')
            ->where('summary.total_cost', fn ($v) => (float) $v === 12.0)
            ->where('summary.gross_profit', fn ($v) => (float) $v === 8.0)
            ->where('summary.voids_total', fn ($v) => (float) $v === 15.0)
            ->where('summary.voids_count', fn ($v) => (int) $v === 1)
            ->where('summary.returns_total', fn ($v) => (float) $v === 25.0)
            ->where('summary.returns_count', fn ($v) => (int) $v === 1)
            ->has('fast_movers')
        );
    }
}
