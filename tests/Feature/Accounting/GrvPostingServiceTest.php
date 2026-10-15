<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\GoodsReceivedVoucher;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\GrvPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GrvPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private GrvPostingService $grvPosting;

    private string $businessId = 'biz-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->grvPosting = app(GrvPostingService::class);

        Tenant::create(['id' => $this->businessId, 'business_name' => $this->businessId, 'owner_email' => 'a@example.com']);
        Business::create(['id' => $this->businessId, 'name' => $this->businessId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->businessId);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->businessId)->where('code', $code)->firstOrFail();
    }

    private function makeSupplierAndPo(): PurchaseOrder
    {
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $this->businessId, 'name' => 'Acme Supplies']);

        return PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->businessId,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'po_number' => 'PO-0001',
            'status' => 'sent',
            'total_ordered' => 500,
            'total_received' => 0,
            'created_by_user_id' => (string) Str::uuid(),
        ]);
    }

    private function makeReceiveMovement(?string $referenceId, float $qty = 10, float $unitCost = 5, ?string $date = null): StockMovement
    {
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $this->businessId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 20, 'track_stock' => true, 'is_active' => true,
        ]);

        $movement = StockMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->businessId,
            'product_id' => $product->id,
            'type' => 'receive',
            'quantity_change' => $qty,
            'unit_cost' => $unitCost,
            'reference_id' => $referenceId,
            'user_id' => (string) Str::uuid(),
        ]);

        if ($date) {
            DB::table('stock_movements')->where('id', $movement->id)->update(['created_at' => $date]);
            $movement->refresh();
        }

        return $movement;
    }

    public function test_a_walk_in_receipt_with_no_reference_id_is_never_posted(): void
    {
        $movement = $this->makeReceiveMovement(null, qty: 10, unitCost: 5);

        $this->grvPosting->recordReceipt($movement);

        $this->assertSame(0, GoodsReceivedVoucher::count());
        $this->assertSame(0.0, $this->account('1200')->balance());
    }

    public function test_a_receipt_whose_reference_id_does_not_match_a_real_po_is_never_posted(): void
    {
        $movement = $this->makeReceiveMovement((string) Str::uuid(), qty: 10, unitCost: 5);

        $this->grvPosting->recordReceipt($movement);

        $this->assertSame(0, GoodsReceivedVoucher::count());
    }

    public function test_non_receive_movement_types_are_never_posted(): void
    {
        $po = $this->makeSupplierAndPo();
        $movement = $this->makeReceiveMovement($po->id, qty: 10, unitCost: 5);
        $movement->update(['type' => 'sale']);

        $this->grvPosting->recordReceipt($movement->fresh());

        $this->assertSame(0, GoodsReceivedVoucher::count());
    }

    public function test_nothing_posts_before_the_accounting_go_live_date(): void
    {
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => '2026-07-01']);
        $po = $this->makeSupplierAndPo();
        $movement = $this->makeReceiveMovement($po->id, qty: 10, unitCost: 5, date: '2026-06-01 09:00:00');

        $this->grvPosting->recordReceipt($movement);

        $this->assertSame(0, GoodsReceivedVoucher::count());
    }
}
