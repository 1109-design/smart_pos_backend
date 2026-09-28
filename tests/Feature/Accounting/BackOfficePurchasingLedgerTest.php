<?php

namespace Tests\Feature\Accounting;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\GoodsReceivedVoucher;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\GrvPostingService;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end coverage for Purchasing & Cash Vault Blueprint, part B's
 * BackOffice surface — recording an invoice against a real GRV and a
 * payment against a real supplier through the actual HTTP routes, not
 * just the services in isolation.
 */
class BackOfficePurchasingLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId, string $role = 'business_owner'): User
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
            'user_email' => $user->email, 'role' => $role, 'business_name' => $tenantId,
            'currency_code' => 'USD',
        ]]);

        return $user;
    }

    private function account(string $tenantId, string $code): GlAccount
    {
        return GlAccount::where('business_id', $tenantId)->where('code', $code)->firstOrFail();
    }

    private function makePostedGrv(string $tenantId, string $supplierId, float $qty, float $unitCost): GoodsReceivedVoucher
    {
        $po = PurchaseOrder::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'supplier_id' => $supplierId,
            'supplier_name' => 'Acme Supplies', 'po_number' => 'PO-0001', 'status' => 'sent',
            'total_ordered' => $qty * $unitCost, 'total_received' => 0, 'created_by_user_id' => (string) Str::uuid(),
        ]);
        $movement = StockMovement::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'product_id' => (string) Str::uuid(),
            'type' => 'receive', 'quantity_change' => $qty, 'unit_cost' => $unitCost,
            'reference_id' => $po->id, 'user_id' => (string) Str::uuid(),
        ]);
        app(GrvPostingService::class)->recordReceipt($movement);

        return GoodsReceivedVoucher::where('purchase_order_id', $po->id)->firstOrFail();
    }

    public function test_recording_an_invoice_through_backoffice_clears_suspense_and_shows_on_the_po_page(): void
    {
        $tenantId = 'tenant-po-invoice-1';
        $this->actingBackOfficeSession($tenantId);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);
        $grv = $this->makePostedGrv($tenantId, $supplier->id, qty: 10, unitCost: 5);

        $response = $this->post("/office/grvs/{$grv->id}/invoice", [
            'invoice_number' => 'INV-100',
            'invoice_date' => '2026-06-05',
            'amount' => 50.0,
        ]);
        $response->assertRedirect();
        $this->assertSame(1, SupplierInvoice::where('grv_id', $grv->id)->count());
        $this->assertSame(0.0, $this->account($tenantId, '2010')->balance());
        $this->assertSame(50.0, $this->account($tenantId, '2000')->balance());

        $poId = PurchaseOrder::first()->id;
        $show = $this->get("/office/purchase-orders/{$poId}");
        $show->assertInertia(fn ($page) => $page
            ->where('grvs.0.invoice.invoice_number', 'INV-100')
            ->where('grvs.0.invoice.amount', 50)
        );
    }

    public function test_a_second_invoice_against_the_same_grv_is_rejected_with_a_validation_error(): void
    {
        $tenantId = 'tenant-po-invoice-2';
        $this->actingBackOfficeSession($tenantId);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);
        $grv = $this->makePostedGrv($tenantId, $supplier->id, qty: 10, unitCost: 5);

        $this->post("/office/grvs/{$grv->id}/invoice", ['invoice_number' => 'INV-1', 'invoice_date' => '2026-06-05', 'amount' => 50.0]);
        $response = $this->post("/office/grvs/{$grv->id}/invoice", ['invoice_number' => 'INV-2', 'invoice_date' => '2026-06-06', 'amount' => 50.0]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(1, SupplierInvoice::where('grv_id', $grv->id)->count());
    }

    public function test_cashier_cannot_record_an_invoice(): void
    {
        $tenantId = 'tenant-po-invoice-3';
        $this->actingBackOfficeSession($tenantId, 'cashier');
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);
        $grv = $this->makePostedGrv($tenantId, $supplier->id, qty: 10, unitCost: 5);

        $this->post("/office/grvs/{$grv->id}/invoice", ['invoice_number' => 'INV-1', 'invoice_date' => '2026-06-05', 'amount' => 50.0])
            ->assertForbidden();
    }

    public function test_recording_a_payment_through_backoffice_reduces_the_suppliers_balance(): void
    {
        $tenantId = 'tenant-supplier-payment-1';
        $this->actingBackOfficeSession($tenantId);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);
        $grv = $this->makePostedGrv($tenantId, $supplier->id, qty: 10, unitCost: 5);
        $this->post("/office/grvs/{$grv->id}/invoice", ['invoice_number' => 'INV-1', 'invoice_date' => '2026-06-05', 'amount' => 50.0]);

        // Fund cash so the payment doesn't get rejected for overdrawing it.
        $journals = app(JournalService::class);
        $header = $journals->createDraft($tenantId, '2026-06-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $this->account($tenantId, '1000')->id, 'debit' => 1000]);
        $journals->addLine($header, ['gl_account_id' => $this->account($tenantId, '3000')->id, 'credit' => 1000]);
        $journals->post($header);

        $response = $this->post("/office/suppliers/{$supplier->id}/payments", [
            'amount' => 20.0, 'payment_date' => '2026-06-10', 'method' => 'cash', 'reference' => 'EFT-1',
        ]);
        $response->assertRedirect();

        $this->assertSame(1, SupplierPayment::where('supplier_id', $supplier->id)->count());
        $this->assertSame(30.0, $this->account($tenantId, '2000')->balance());

        $show = $this->get("/office/suppliers/{$supplier->id}");
        $show->assertInertia(fn ($page) => $page->where('aging.total_outstanding', 30));
    }

    public function test_cashier_cannot_record_a_supplier_payment(): void
    {
        $tenantId = 'tenant-supplier-payment-2';
        $this->actingBackOfficeSession($tenantId, 'cashier');
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);

        $this->post("/office/suppliers/{$supplier->id}/payments", ['amount' => 20.0, 'payment_date' => '2026-06-10', 'method' => 'cash'])
            ->assertForbidden();
    }
}
