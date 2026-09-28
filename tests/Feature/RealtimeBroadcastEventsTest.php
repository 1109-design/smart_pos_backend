<?php

namespace Tests\Feature;

use App\Events\ApprovalRequestChanged;
use App\Events\AssetChanged;
use App\Events\BankAccountChanged;
use App\Events\CreditNoteChanged;
use App\Events\CustomerChanged;
use App\Events\ExpenseChanged;
use App\Events\GoodsReceivedVoucherRecorded;
use App\Events\InvoiceChanged;
use App\Events\InvoicePaymentRecorded;
use App\Events\ProductPriceChanged;
use App\Events\PurchaseOrderChanged;
use App\Events\QuotationChanged;
use App\Events\SalaryPaymentRecorded;
use App\Events\ShiftStatusChanged;
use App\Events\StockLevelChanged;
use App\Events\StockTakeChanged;
use App\Events\StockTransferChanged;
use App\Events\SupplierChanged;
use App\Events\SupplierInvoiceChanged;
use App\Events\SupplierPaymentAllocated;
use App\Events\SupplierPaymentRecorded;
use App\Events\TillCashMovementRecorded;
use App\Events\TransactionRecorded;
use App\Models\ApprovalRequest;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\GoodsReceivedVoucher;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\SalaryPayment;
use App\Models\Shift;
use App\Models\StockTake;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\TillCashMovement;
use App\Models\Transaction;
use App\Services\SyncProcessor;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves the realtime broadcast events fire from the right model changes
 * (and only those). Originally scoped to a "priority events for this
 * milestone" subset (shift/till-cash/invoice-payment/stock/price); the
 * offline-first audit (2026-09-06) extended coverage to the remaining
 * operational data the architecture spec calls for — transactions,
 * customers, purchase orders, stock transfers, and approvals.
 */
class RealtimeBroadcastEventsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $id): void
    {
        Tenant::create(['id' => $id, 'business_name' => $id, 'owner_email' => $id.'@example.com']);
    }

    public function test_stock_quantity_change_dispatches_stock_level_changed(): void
    {
        Event::fake([StockLevelChanged::class]);

        $tenantId = 'tenant-events-stock';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);
        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget', 'price' => 10]);
        $stock = ProductStock::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 5]);

        $stock->update(['quantity' => 8]);

        Event::assertDispatched(StockLevelChanged::class, fn ($e) => $e->businessId === $tenantId
            && $e->locationId === $location->id
            && $e->productId === $product->id);
    }

    public function test_stock_reserved_quantity_change_alone_does_not_dispatch(): void
    {
        Event::fake([StockLevelChanged::class]);

        $tenantId = 'tenant-events-stock-reserved';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);
        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget', 'price' => 10]);
        $stock = ProductStock::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 5]);

        $stock->update(['reserved_quantity' => 2]);

        Event::assertNotDispatched(StockLevelChanged::class);
    }

    /**
     * Regression: LocationService::reserveInTransit/markIncoming mutate
     * in_transit_quantity via a query-builder increment(), which never fires
     * ProductStock's Eloquent 'updated' event at all — so the model-level
     * hook alone could never broadcast an in-transit change. publishStock()
     * (called by TransferService right after every such mutation) now
     * dispatches StockLevelChanged explicitly instead of relying on it.
     */
    public function test_dispatching_a_transfer_broadcasts_stock_level_changed_for_both_locations(): void
    {
        Event::fake([StockLevelChanged::class]);

        $tenantId = 'tenant-events-transfer-intransit';
        $this->makeTenant($tenantId);
        $warehouse = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse', 'type' => 'warehouse']);
        $shop = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop', 'type' => 'shop']);
        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget', 'price' => 10, 'track_stock' => true]);

        app(SyncProcessor::class)->process('stock_movements', (string) Str::uuid(), 'upsert', [
            'business_id' => $tenantId, 'location_id' => $warehouse->id, 'product_id' => $product->id,
            'type' => 'opening_stock', 'quantity_change' => 20, 'reason' => 'Test',
        ]);

        $transfer = app(TransferService::class)->request([
            'business_id' => $tenantId,
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'requested_by_user_id' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'qty_requested' => 5]],
        ]);

        Event::fake([StockLevelChanged::class]);
        app(TransferService::class)->dispatch($transfer->id, [
            ['item_id' => $transfer->items->first()->id, 'qty_sent' => 5],
        ], (string) Str::uuid());

        Event::assertDispatched(StockLevelChanged::class, fn ($e) => $e->locationId === $warehouse->id && $e->productId === $product->id);
        Event::assertDispatched(StockLevelChanged::class, fn ($e) => $e->locationId === $shop->id && $e->productId === $product->id);
    }

    public function test_product_price_change_dispatches_product_price_changed(): void
    {
        Event::fake([ProductPriceChanged::class]);

        $tenantId = 'tenant-events-price';
        $this->makeTenant($tenantId);
        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget', 'price' => 10]);

        $product->update(['price' => 12]);

        Event::assertDispatched(ProductPriceChanged::class, fn ($e) => $e->businessId === $tenantId && $e->productId === $product->id);
    }

    public function test_product_name_change_alone_does_not_dispatch(): void
    {
        Event::fake([ProductPriceChanged::class]);

        $tenantId = 'tenant-events-price-name';
        $this->makeTenant($tenantId);
        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget', 'price' => 10]);

        $product->update(['name' => 'Renamed Widget']);

        Event::assertNotDispatched(ProductPriceChanged::class);
    }

    public function test_shift_creation_dispatches_shift_status_changed(): void
    {
        Event::fake([ShiftStatusChanged::class]);

        $tenantId = 'tenant-events-shift-open';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);

        $shift = Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'cashier_id' => (string) Str::uuid(),
            'opened_at' => now(),
            'status' => 'open',
        ]);

        Event::assertDispatched(ShiftStatusChanged::class, fn ($e) => $e->shiftId === $shift->id && $e->status === 'open');
    }

    public function test_shift_closing_dispatches_shift_status_changed(): void
    {
        $tenantId = 'tenant-events-shift-close';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);
        $shift = Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'cashier_id' => (string) Str::uuid(),
            'opened_at' => now(),
            'status' => 'open',
        ]);

        Event::fake([ShiftStatusChanged::class]);
        $shift->update(['status' => 'closed', 'closed_at' => now()]);

        Event::assertDispatched(ShiftStatusChanged::class, fn ($e) => $e->shiftId === $shift->id && $e->status === 'closed');
    }

    public function test_shift_notes_change_alone_does_not_dispatch(): void
    {
        $tenantId = 'tenant-events-shift-notes';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);
        $shift = Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'cashier_id' => (string) Str::uuid(),
            'opened_at' => now(),
            'status' => 'open',
        ]);

        Event::fake([ShiftStatusChanged::class]);
        $shift->update(['notes' => 'till was short by $2']);

        Event::assertNotDispatched(ShiftStatusChanged::class);
    }

    public function test_till_cash_movement_creation_dispatches_till_cash_movement_recorded(): void
    {
        Event::fake([TillCashMovementRecorded::class]);

        $tenantId = 'tenant-events-cash';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);
        $till = Till::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $location->id, 'name' => 'Till 1', 'register_number' => 1]);

        $movement = TillCashMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'till_id' => $till->id,
            'type' => 'cash_in',
            'amount' => 50,
            'recorded_by_user_id' => (string) Str::uuid(),
        ]);

        Event::assertDispatched(TillCashMovementRecorded::class, fn ($e) => $e->movementId === $movement->id
            && $e->tillId === $till->id
            && $e->businessId === $tenantId);
    }

    public function test_invoice_payment_creation_dispatches_invoice_payment_recorded(): void
    {
        Event::fake([InvoicePaymentRecorded::class]);

        $tenantId = 'tenant-events-invoice-payment';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);
        $invoice = Invoice::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'customer_id' => (string) Str::uuid(),
            'invoice_number' => 'INV-202608-001',
            'status' => 'draft',
            'issue_date' => now(),
            'total' => 100,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        $payment = InvoicePayment::create([
            'id' => (string) Str::uuid(),
            'invoice_id' => $invoice->id,
            'method' => 'cash',
            'amount' => 50,
            'currency_code' => 'USD',
            'base_equivalent' => 50,
            'recorded_by_user_id' => (string) Str::uuid(),
            'paid_at' => now(),
        ]);

        Event::assertDispatched(InvoicePaymentRecorded::class, fn ($e) => $e->paymentId === $payment->id
            && $e->invoiceId === $invoice->id
            && $e->businessId === $tenantId
            && $e->locationId === $location->id);
    }

    public function test_invoice_payment_without_a_location_does_not_dispatch(): void
    {
        Event::fake([InvoicePaymentRecorded::class]);

        $tenantId = 'tenant-events-invoice-payment-no-location';
        $this->makeTenant($tenantId);
        $invoice = Invoice::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'customer_id' => (string) Str::uuid(),
            'invoice_number' => 'INV-202608-002',
            'status' => 'draft',
            'issue_date' => now(),
            'total' => 100,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        InvoicePayment::create([
            'id' => (string) Str::uuid(),
            'invoice_id' => $invoice->id,
            'method' => 'cash',
            'amount' => 50,
            'currency_code' => 'USD',
            'base_equivalent' => 50,
            'recorded_by_user_id' => (string) Str::uuid(),
            'paid_at' => now(),
        ]);

        Event::assertNotDispatched(InvoicePaymentRecorded::class);
    }

    public function test_a_new_sale_dispatches_transaction_recorded(): void
    {
        Event::fake([TransactionRecorded::class]);

        $tenantId = 'tenant-events-transaction';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);

        $transaction = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'user_id' => (string) Str::uuid(),
            'subtotal' => 10,
            'tax_total' => 0,
            'total' => 10,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => '202609-EVT-1',
        ]);

        Event::assertDispatched(TransactionRecorded::class, fn ($e) => $e->businessId === $tenantId
            && $e->locationId === $location->id
            && $e->transactionId === $transaction->id);
    }

    public function test_voiding_a_transaction_dispatches_transaction_recorded(): void
    {
        $tenantId = 'tenant-events-transaction-void';
        $this->makeTenant($tenantId);
        $transaction = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'user_id' => (string) Str::uuid(),
            'subtotal' => 10,
            'tax_total' => 0,
            'total' => 10,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => '202609-EVT-2',
        ]);

        Event::fake([TransactionRecorded::class]);
        $transaction->update(['status' => 'voided', 'void_reason' => 'Customer changed their mind']);

        Event::assertDispatched(TransactionRecorded::class, fn ($e) => $e->transactionId === $transaction->id);
    }

    public function test_a_transaction_notes_change_alone_does_not_dispatch(): void
    {
        $tenantId = 'tenant-events-transaction-notes';
        $this->makeTenant($tenantId);
        $transaction = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'user_id' => (string) Str::uuid(),
            'subtotal' => 10,
            'tax_total' => 0,
            'total' => 10,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => '202609-EVT-3',
        ]);

        Event::fake([TransactionRecorded::class]);
        $transaction->update(['notes' => 'Gift wrapped']);

        Event::assertNotDispatched(TransactionRecorded::class);
    }

    public function test_customer_creation_dispatches_customer_changed(): void
    {
        Event::fake([CustomerChanged::class]);

        $tenantId = 'tenant-events-customer';
        $this->makeTenant($tenantId);

        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Jane Doe']);

        Event::assertDispatched(CustomerChanged::class, fn ($e) => $e->businessId === $tenantId && $e->customerId === $customer->id);
    }

    public function test_customer_loyalty_balance_update_dispatches_customer_changed(): void
    {
        $tenantId = 'tenant-events-customer-loyalty';
        $this->makeTenant($tenantId);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Jane Doe']);

        Event::fake([CustomerChanged::class]);
        $customer->update(['loyalty_points' => 50]);

        Event::assertDispatched(CustomerChanged::class, fn ($e) => $e->customerId === $customer->id);
    }

    public function test_purchase_order_creation_dispatches_purchase_order_changed(): void
    {
        Event::fake([PurchaseOrderChanged::class]);

        $tenantId = 'tenant-events-po';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse']);

        $po = PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'receiving_location_id' => $location->id,
            'po_number' => 'PO-202609-EVT-1',
            'status' => 'draft',
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        Event::assertDispatched(PurchaseOrderChanged::class, fn ($e) => $e->businessId === $tenantId
            && $e->locationId === $location->id
            && $e->purchaseOrderId === $po->id);
    }

    public function test_purchase_order_with_no_receiving_location_broadcasts_business_wide(): void
    {
        Event::fake([PurchaseOrderChanged::class]);

        $tenantId = 'tenant-events-po-no-location';
        $this->makeTenant($tenantId);

        $po = PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'po_number' => 'PO-202609-EVT-2',
            'status' => 'draft',
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        Event::assertDispatched(PurchaseOrderChanged::class, fn ($e) => $e->locationId === null && $e->purchaseOrderId === $po->id);
    }

    public function test_purchase_order_status_change_dispatches_purchase_order_changed(): void
    {
        $tenantId = 'tenant-events-po-status';
        $this->makeTenant($tenantId);
        $po = PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'po_number' => 'PO-202609-EVT-3',
            'status' => 'draft',
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        Event::fake([PurchaseOrderChanged::class]);
        $po->update(['status' => 'sent']);

        Event::assertDispatched(PurchaseOrderChanged::class, fn ($e) => $e->purchaseOrderId === $po->id);
    }

    public function test_stock_transfer_creation_dispatches_to_both_locations(): void
    {
        Event::fake([StockTransferChanged::class]);

        $tenantId = 'tenant-events-transfer';
        $this->makeTenant($tenantId);
        $warehouse = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse']);
        $shop = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);

        $transfer = StockTransfer::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'transfer_number' => 'TRF-202609-EVT-1',
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'status' => 'pending',
            'requested_by_user_id' => (string) Str::uuid(),
        ]);

        Event::assertDispatched(StockTransferChanged::class, fn ($e) => $e->fromLocationId === $warehouse->id
            && $e->toLocationId === $shop->id
            && $e->transferId === $transfer->id);
    }

    public function test_stock_transfer_status_change_dispatches_stock_transfer_changed(): void
    {
        $tenantId = 'tenant-events-transfer-status';
        $this->makeTenant($tenantId);
        $warehouse = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse']);
        $shop = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);
        $transfer = StockTransfer::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'transfer_number' => 'TRF-202609-EVT-2',
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'status' => 'pending',
            'requested_by_user_id' => (string) Str::uuid(),
        ]);

        Event::fake([StockTransferChanged::class]);
        $transfer->update(['status' => 'in_transit']);

        Event::assertDispatched(StockTransferChanged::class, fn ($e) => $e->transferId === $transfer->id);
    }

    public function test_a_transfer_notes_change_alone_does_not_dispatch(): void
    {
        $tenantId = 'tenant-events-transfer-notes';
        $this->makeTenant($tenantId);
        $warehouse = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse']);
        $shop = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);
        $transfer = StockTransfer::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'transfer_number' => 'TRF-202609-EVT-3',
            'from_location_id' => $warehouse->id,
            'to_location_id' => $shop->id,
            'status' => 'pending',
            'requested_by_user_id' => (string) Str::uuid(),
        ]);

        Event::fake([StockTransferChanged::class]);
        $transfer->update(['notes' => 'Fragile — handle with care']);

        Event::assertNotDispatched(StockTransferChanged::class);
    }

    public function test_approval_request_creation_dispatches_approval_request_changed(): void
    {
        Event::fake([ApprovalRequestChanged::class]);

        $tenantId = 'tenant-events-approval';
        $this->makeTenant($tenantId);

        $request = ApprovalRequest::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'subject_type' => 'Transaction',
            'subject_id' => (string) Str::uuid(),
            'action' => 'void_transaction',
            'requested_by_user_id' => (string) Str::uuid(),
            'status' => 'pending',
        ]);

        Event::assertDispatched(ApprovalRequestChanged::class, fn ($e) => $e->businessId === $tenantId && $e->approvalRequestId === $request->id);
    }

    public function test_approval_request_resolution_dispatches_approval_request_changed(): void
    {
        $tenantId = 'tenant-events-approval-resolve';
        $this->makeTenant($tenantId);
        $request = ApprovalRequest::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'subject_type' => 'Transaction',
            'subject_id' => (string) Str::uuid(),
            'action' => 'void_transaction',
            'requested_by_user_id' => (string) Str::uuid(),
            'status' => 'pending',
        ]);

        Event::fake([ApprovalRequestChanged::class]);
        $request->update(['status' => 'approved', 'approver_user_id' => (string) Str::uuid(), 'approved_at' => now()]);

        Event::assertDispatched(ApprovalRequestChanged::class, fn ($e) => $e->approvalRequestId === $request->id);
    }

    public function test_invoice_creation_dispatches_invoice_changed(): void
    {
        Event::fake([InvoiceChanged::class]);

        $tenantId = 'tenant-events-invoice';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);

        $invoice = Invoice::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'customer_id' => (string) Str::uuid(),
            'invoice_number' => 'INV-202609-EVT-1',
            'status' => 'draft',
            'issue_date' => now(),
            'total' => 100,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        Event::assertDispatched(InvoiceChanged::class, fn ($e) => $e->businessId === $tenantId
            && $e->locationId === $location->id
            && $e->invoiceId === $invoice->id);
    }

    public function test_invoice_status_change_dispatches_invoice_changed(): void
    {
        $tenantId = 'tenant-events-invoice-status';
        $this->makeTenant($tenantId);
        $invoice = Invoice::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'customer_id' => (string) Str::uuid(),
            'invoice_number' => 'INV-202609-EVT-2',
            'status' => 'draft',
            'issue_date' => now(),
            'total' => 100,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        Event::fake([InvoiceChanged::class]);
        $invoice->update(['status' => 'sent']);

        Event::assertDispatched(InvoiceChanged::class, fn ($e) => $e->invoiceId === $invoice->id && $e->locationId === null);
    }

    public function test_quotation_creation_dispatches_quotation_changed(): void
    {
        Event::fake([QuotationChanged::class]);

        $tenantId = 'tenant-events-quotation';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);

        $quotation = Quotation::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'customer_id' => (string) Str::uuid(),
            'quote_number' => 'QUO-202609-EVT-1',
            'status' => 'draft',
            'total' => 100,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        Event::assertDispatched(QuotationChanged::class, fn ($e) => $e->businessId === $tenantId
            && $e->locationId === $location->id
            && $e->quotationId === $quotation->id);
    }

    public function test_supplier_creation_dispatches_supplier_changed(): void
    {
        Event::fake([SupplierChanged::class]);

        $tenantId = 'tenant-events-supplier';
        $this->makeTenant($tenantId);

        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);

        Event::assertDispatched(SupplierChanged::class, fn ($e) => $e->businessId === $tenantId && $e->supplierId === $supplier->id);
    }

    public function test_supplier_payment_creation_dispatches_supplier_payment_recorded(): void
    {
        Event::fake([SupplierPaymentRecorded::class]);

        $tenantId = 'tenant-events-supplier-payment';
        $this->makeTenant($tenantId);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);

        $payment = SupplierPayment::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'supplier_id' => $supplier->id,
            'amount' => 50,
            'currency_code' => 'USD',
            'payment_date' => now(),
            'method' => 'cash',
            'recorded_by_user_id' => (string) Str::uuid(),
        ]);

        Event::assertDispatched(SupplierPaymentRecorded::class, fn ($e) => $e->businessId === $tenantId
            && $e->supplierId === $supplier->id
            && $e->paymentId === $payment->id);
    }

    public function test_expense_creation_dispatches_expense_changed(): void
    {
        Event::fake([ExpenseChanged::class]);

        $tenantId = 'tenant-events-expense';
        $this->makeTenant($tenantId);

        $expense = Expense::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'recorded_by_user_id' => (string) Str::uuid(),
            'category' => 'utilities',
            'description' => 'Electricity',
            'amount' => 30,
            'currency_code' => 'USD',
            'base_equivalent' => 30,
            'payment_method' => 'cash',
            'expense_date' => now(),
        ]);

        Event::assertDispatched(ExpenseChanged::class, fn ($e) => $e->businessId === $tenantId && $e->expenseId === $expense->id);
    }

    public function test_asset_creation_dispatches_asset_changed(): void
    {
        Event::fake([AssetChanged::class]);

        $tenantId = 'tenant-events-asset';
        $this->makeTenant($tenantId);

        $asset = Asset::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'asset_number' => 'AST-001',
            'name' => 'Delivery Van',
            'category' => 'vehicle',
            'acquisition_date' => now(),
            'acquisition_cost' => 20000,
            'salvage_value' => 2000,
            'useful_life_months' => 60,
            'funding_method' => 'cash',
            'status' => 'active',
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        Event::assertDispatched(AssetChanged::class, fn ($e) => $e->businessId === $tenantId && $e->assetId === $asset->id);
    }

    public function test_salary_payment_creation_dispatches_salary_payment_recorded(): void
    {
        Event::fake([SalaryPaymentRecorded::class]);

        $tenantId = 'tenant-events-salary';
        $this->makeTenant($tenantId);
        $employee = Employee::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Jane Cashier',
            'pay_type' => 'salary',
            'salary_amount' => 500,
            'currency_code' => 'USD',
            'status' => 'active',
        ]);

        $payment = SalaryPayment::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'employee_id' => $employee->id,
            'period' => '2026-09',
            'amount' => 500,
            'currency_code' => 'USD',
            'base_equivalent' => 500,
            'paid_by_user_id' => (string) Str::uuid(),
            'paid_at' => now(),
        ]);

        Event::assertDispatched(SalaryPaymentRecorded::class, fn ($e) => $e->businessId === $tenantId
            && $e->employeeId === $employee->id
            && $e->salaryPaymentId === $payment->id);
    }

    public function test_stock_take_creation_dispatches_stock_take_changed(): void
    {
        Event::fake([StockTakeChanged::class]);

        $tenantId = 'tenant-events-stock-take';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);

        $stockTake = StockTake::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'title' => 'Monthly Count',
            'status' => 'draft',
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        Event::assertDispatched(StockTakeChanged::class, fn ($e) => $e->businessId === $tenantId
            && $e->locationId === $location->id
            && $e->stockTakeId === $stockTake->id);
    }

    public function test_credit_note_creation_dispatches_credit_note_changed(): void
    {
        Event::fake([CreditNoteChanged::class]);

        $tenantId = 'tenant-events-credit-note';
        $this->makeTenant($tenantId);
        $invoice = Invoice::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'customer_id' => (string) Str::uuid(),
            'invoice_number' => 'INV-202609-EVT-3',
            'status' => 'sent',
            'issue_date' => now(),
            'total' => 100,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        $creditNote = CreditNote::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'invoice_id' => $invoice->id,
            'customer_id' => (string) Str::uuid(),
            'credit_note_number' => 'CN-202609-EVT-1',
            'reason' => 'Damaged goods',
            'subtotal' => 10,
            'tax_total' => 0,
            'total' => 10,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        Event::assertDispatched(CreditNoteChanged::class, fn ($e) => $e->businessId === $tenantId && $e->creditNoteId === $creditNote->id);
    }

    public function test_bank_account_creation_dispatches_bank_account_changed(): void
    {
        Event::fake([BankAccountChanged::class]);

        $tenantId = 'tenant-events-bank-account';
        $this->makeTenant($tenantId);

        $account = BankAccount::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'CBZ Main Account',
            'currency_code' => 'ZWG',
            'gl_account_id' => (string) Str::uuid(),
        ]);

        Event::assertDispatched(BankAccountChanged::class, fn ($e) => $e->businessId === $tenantId && $e->bankAccountId === $account->id);
    }

    public function test_bank_account_currency_change_dispatches_bank_account_changed(): void
    {
        $tenantId = 'tenant-events-bank-account-currency';
        $this->makeTenant($tenantId);
        $account = BankAccount::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'CBZ Main Account',
            'currency_code' => 'USD',
            'gl_account_id' => (string) Str::uuid(),
        ]);

        Event::fake([BankAccountChanged::class]);
        $account->update(['currency_code' => 'ZWG']);

        Event::assertDispatched(BankAccountChanged::class, fn ($e) => $e->bankAccountId === $account->id);
    }

    public function test_goods_received_voucher_creation_dispatches_recorded_event(): void
    {
        Event::fake([GoodsReceivedVoucherRecorded::class]);

        $tenantId = 'tenant-events-grv';
        $this->makeTenant($tenantId);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);
        $po = PurchaseOrder::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'supplier_id' => $supplier->id,
            'po_number' => 'PO-1', 'status' => 'received', 'created_by_user_id' => (string) Str::uuid(),
        ]);

        $grv = GoodsReceivedVoucher::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'grv_number' => 'GRV-2026-00000001',
            'purchase_order_id' => $po->id, 'supplier_id' => $supplier->id, 'received_date' => now(),
        ]);

        Event::assertDispatched(GoodsReceivedVoucherRecorded::class, fn ($e) => $e->businessId === $tenantId
            && $e->grvId === $grv->id
            && $e->purchaseOrderId === $po->id);
    }

    public function test_supplier_invoice_creation_and_status_change_dispatch_changed_event(): void
    {
        Event::fake([SupplierInvoiceChanged::class]);

        $tenantId = 'tenant-events-supplier-invoice';
        $this->makeTenant($tenantId);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);

        $invoice = SupplierInvoice::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-1', 'invoice_date' => now(), 'amount' => 100, 'status' => 'draft',
        ]);

        Event::assertDispatched(SupplierInvoiceChanged::class, fn ($e) => $e->businessId === $tenantId
            && $e->invoiceId === $invoice->id
            && $e->status === 'draft');

        Event::fake([SupplierInvoiceChanged::class]);
        $invoice->update(['status' => 'approved']);

        Event::assertDispatched(SupplierInvoiceChanged::class, fn ($e) => $e->invoiceId === $invoice->id
            && $e->status === 'approved');
    }

    public function test_supplier_payment_allocation_creation_dispatches_allocated_event(): void
    {
        Event::fake([SupplierPaymentAllocated::class]);

        $tenantId = 'tenant-events-supplier-allocation';
        $this->makeTenant($tenantId);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);
        $invoice = SupplierInvoice::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-2', 'invoice_date' => now(), 'amount' => 100, 'status' => 'posted',
        ]);
        $payment = SupplierPayment::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'supplier_id' => $supplier->id,
            'amount' => 100, 'currency_code' => 'USD', 'payment_date' => now(), 'method' => 'cash',
            'recorded_by_user_id' => (string) Str::uuid(),
        ]);

        $allocation = SupplierPaymentAllocation::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'supplier_payment_id' => $payment->id,
            'supplier_invoice_id' => $invoice->id, 'amount' => 100,
        ]);

        Event::assertDispatched(SupplierPaymentAllocated::class, fn ($e) => $e->businessId === $tenantId
            && $e->supplierPaymentId === $payment->id
            && $e->supplierInvoiceId === $invoice->id
            && $e->supplierInvoiceId === $allocation->supplier_invoice_id);
    }
}
