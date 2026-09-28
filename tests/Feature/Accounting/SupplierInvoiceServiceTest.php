<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\GoodsReceivedVoucher;
use App\Models\GrvItem;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\GrvPostingService;
use App\Services\Accounting\PartyLedgerService;
use App\Services\Accounting\SupplierInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SupplierInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private SupplierInvoiceService $invoices;

    private string $businessId = 'biz-1';

    private string $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invoices = app(SupplierInvoiceService::class);
        $this->supplierId = (string) Str::uuid();

        Tenant::create(['id' => $this->businessId, 'business_name' => $this->businessId, 'owner_email' => 'a@example.com']);
        Business::create(['id' => $this->businessId, 'name' => $this->businessId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->businessId);
        Supplier::create(['id' => $this->supplierId, 'business_id' => $this->businessId, 'name' => 'Acme Supplies']);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->businessId)->where('code', $code)->firstOrFail();
    }

    /**
     * Goes through the real part-A flow (GrvPostingService), not a bare
     * GRV/GrvItem insert — otherwise the GRV would exist with no matching
     * "Cr GRN Suspense" ever posted for part B's invoice to clear, which
     * can't happen in production (a GRV only ever gets created by that
     * service, and it always posts the moment it's created).
     */
    private function makeGrv(float $qty, float $unitCost, ?array $additionalCosts = null): GoodsReceivedVoucher
    {
        $po = PurchaseOrder::create([
            'id' => (string) Str::uuid(), 'business_id' => $this->businessId, 'supplier_id' => $this->supplierId,
            'supplier_name' => 'Acme Supplies', 'po_number' => 'PO-0001', 'status' => 'sent',
            'total_ordered' => $qty * $unitCost, 'total_received' => 0,
            'created_by_user_id' => (string) Str::uuid(),
            'additional_costs_json' => $additionalCosts,
        ]);

        $movement = StockMovement::create([
            'id' => (string) Str::uuid(), 'business_id' => $this->businessId,
            'product_id' => (string) Str::uuid(), 'type' => 'receive',
            'quantity_change' => $qty, 'unit_cost' => $unitCost,
            'reference_id' => $po->id, 'user_id' => (string) Str::uuid(),
        ]);
        app(GrvPostingService::class)->recordReceipt($movement);

        return GoodsReceivedVoucher::where('purchase_order_id', $po->id)->firstOrFail();
    }

    public function test_an_invoice_matching_the_grv_exactly_clears_suspense_into_payable_with_no_variance(): void
    {
        $grv = $this->makeGrv(qty: 10, unitCost: 5); // GRV value = 50

        $invoice = $this->invoices->recordInvoice($grv, 'INV-001', '2026-06-05', 50.0);

        $this->assertSame(0.0, $this->account('2010')->balance()); // GRN Suspense cleared
        $this->assertSame(50.0, $this->account('2000')->balance()); // Accounts Payable raised
        $this->assertSame(0.0, $this->account('5010')->balance()); // no variance

        $line = GeneralLedgerEntry::where('gl_account_id', $this->account('2000')->id)->first();
        $this->assertSame('supplier', $line->party_type);
        $this->assertSame($this->supplierId, $line->party_id);
    }

    public function test_an_invoice_higher_than_the_grv_posts_an_unfavorable_variance(): void
    {
        $grv = $this->makeGrv(qty: 10, unitCost: 5); // GRV value = 50

        $this->invoices->recordInvoice($grv, 'INV-002', '2026-06-05', 55.0);

        $this->assertSame(0.0, $this->account('2010')->balance());
        $this->assertSame(55.0, $this->account('2000')->balance());
        $this->assertSame(5.0, $this->account('5010')->balance()); // PPV debited (expense-normal, positive = unfavorable)
    }

    public function test_an_invoice_lower_than_the_grv_posts_a_favorable_variance(): void
    {
        $grv = $this->makeGrv(qty: 10, unitCost: 5); // GRV value = 50

        $this->invoices->recordInvoice($grv, 'INV-003', '2026-06-05', 45.0);

        $this->assertSame(0.0, $this->account('2010')->balance());
        $this->assertSame(45.0, $this->account('2000')->balance());
        $this->assertSame(-5.0, $this->account('5010')->balance()); // PPV credited (favorable, negative on an expense account)
    }

    public function test_only_one_invoice_can_be_recorded_per_grv(): void
    {
        $grv = $this->makeGrv(qty: 10, unitCost: 5);
        $this->invoices->recordInvoice($grv, 'INV-004', '2026-06-05', 50.0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been recorded');

        $this->invoices->recordInvoice($grv->fresh(), 'INV-005', '2026-06-06', 50.0);
    }

    public function test_recording_an_invoice_is_refused_when_accounting_is_not_live(): void
    {
        // The GRV itself must be created while live (GrvPostingService has
        // its own go-live gate) — this test is about the invoice service's
        // own check, so accounting only goes dark afterward.
        $grv = $this->makeGrv(qty: 10, unitCost: 5);
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not been switched on');

        $this->invoices->recordInvoice($grv, 'INV-006', '2026-06-05', 50.0);
    }

    public function test_additional_costs_are_allocated_pro_rata_into_landed_unit_cost(): void
    {
        $grv = $this->makeGrv(qty: 10, unitCost: 5, additionalCosts: ['freight' => 10]); // GRV value 50 + 10 freight = 60 total, 10 units -> 6.00 landed

        $this->invoices->recordInvoice($grv, 'INV-007', '2026-06-05', 50.0);

        $item = GrvItem::where('grv_id', $grv->id)->first();
        $this->assertSame(6.0, (float) $item->landed_unit_cost);
        // Landed cost is visibility only — it must not affect what actually posted.
        $this->assertSame(0.0, $this->account('2010')->balance());
        $this->assertSame(50.0, $this->account('2000')->balance());
    }

    public function test_a_grv_with_no_supplier_cannot_have_an_invoice_recorded_against_it(): void
    {
        $grv = $this->makeGrv(qty: 10, unitCost: 5);
        $grv->update(['supplier_id' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no supplier on file');

        $this->invoices->recordInvoice($grv->fresh(), 'INV-008', '2026-06-05', 50.0);
    }

    public function test_supplier_statement_reflects_the_posted_invoice(): void
    {
        $grv = $this->makeGrv(qty: 10, unitCost: 5);
        $this->invoices->recordInvoice($grv, 'INV-009', '2026-06-05', 50.0);

        $ledger = app(PartyLedgerService::class);
        $this->assertSame(50.0, $ledger->currentBalance($this->businessId, 'supplier', $this->supplierId));
    }
}
