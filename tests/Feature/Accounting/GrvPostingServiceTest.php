<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\GoodsReceivedVoucher;
use App\Models\GrvItem;
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

    public function test_a_po_linked_receipt_creates_a_grv_and_posts_inventory_against_grn_suspense(): void
    {
        $po = $this->makeSupplierAndPo();
        $movement = $this->makeReceiveMovement($po->id, qty: 10, unitCost: 5);

        $this->grvPosting->recordReceipt($movement);

        $grv = GoodsReceivedVoucher::where('purchase_order_id', $po->id)->first();
        $this->assertNotNull($grv);
        $this->assertSame($po->supplier_id, $grv->supplier_id);
        $this->assertStringStartsWith('GRV-', $grv->grv_number);

        $item = GrvItem::where('grv_id', $grv->id)->first();
        $this->assertSame('10.0000', $item->quantity_received);
        $this->assertSame('10.0000', $item->quantity_accepted);
        $this->assertSame('0.0000', $item->quantity_rejected);
        $this->assertSame($movement->id, $item->stock_movement_id);

        $journal = JournalHeader::where('source_type', 'grv')->where('source_id', $grv->id)->first();
        $this->assertNotNull($journal);
        $this->assertSame('posted', $journal->status);
        $this->assertSame(50.0, $this->account('1200')->balance()); // Inventory debited
        // GRN Suspense is a liability (credit-normal) — crediting it correctly
        // shows as a positive balance, not negative.
        $this->assertSame(50.0, $this->account('2010')->balance());
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

    public function test_two_receipts_against_the_same_po_on_the_same_day_share_one_grv(): void
    {
        $po = $this->makeSupplierAndPo();
        $first = $this->makeReceiveMovement($po->id, qty: 5, unitCost: 4, date: '2026-06-01 09:00:00');
        $second = $this->makeReceiveMovement($po->id, qty: 3, unitCost: 4, date: '2026-06-01 15:00:00');

        $this->grvPosting->recordReceipt($first);
        $this->grvPosting->recordReceipt($second);

        $this->assertSame(1, GoodsReceivedVoucher::count());
        $grv = GoodsReceivedVoucher::first();
        $this->assertSame(2, GrvItem::where('grv_id', $grv->id)->count());
        $this->assertSame(2, JournalHeader::where('source_type', 'grv')->where('source_id', $grv->id)->count());
        $this->assertSame(32.0, $this->account('1200')->balance()); // (5*4) + (3*4)
    }

    public function test_a_receipt_on_a_different_day_against_the_same_po_starts_a_new_grv(): void
    {
        $po = $this->makeSupplierAndPo();
        $first = $this->makeReceiveMovement($po->id, qty: 5, unitCost: 4, date: '2026-06-01 09:00:00');
        $second = $this->makeReceiveMovement($po->id, qty: 5, unitCost: 4, date: '2026-06-02 09:00:00');

        $this->grvPosting->recordReceipt($first);
        $this->grvPosting->recordReceipt($second);

        $this->assertSame(2, GoodsReceivedVoucher::count());
    }

    public function test_processing_the_same_movement_twice_does_not_double_post(): void
    {
        $po = $this->makeSupplierAndPo();
        $movement = $this->makeReceiveMovement($po->id, qty: 10, unitCost: 5);

        $this->grvPosting->recordReceipt($movement);
        $this->grvPosting->recordReceipt($movement);

        $this->assertSame(1, GrvItem::count());
        $this->assertSame(50.0, $this->account('1200')->balance());
    }

    public function test_nothing_posts_before_the_accounting_go_live_date(): void
    {
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => '2026-07-01']);
        $po = $this->makeSupplierAndPo();
        $movement = $this->makeReceiveMovement($po->id, qty: 10, unitCost: 5, date: '2026-06-01 09:00:00');

        $this->grvPosting->recordReceipt($movement);

        $this->assertSame(0, GoodsReceivedVoucher::count());
    }

    public function test_grv_numbers_are_sequential_per_business(): void
    {
        $po = $this->makeSupplierAndPo();
        $first = $this->makeReceiveMovement($po->id, qty: 5, unitCost: 4, date: '2026-06-01 09:00:00');
        $second = $this->makeReceiveMovement($po->id, qty: 5, unitCost: 4, date: '2026-06-02 09:00:00');

        $this->grvPosting->recordReceipt($first);
        $this->grvPosting->recordReceipt($second);

        $numbers = GoodsReceivedVoucher::orderBy('grv_number')->pluck('grv_number')->all();
        $this->assertSame(['GRV-2026-00001', 'GRV-2026-00002'], $numbers);
    }
}
