<?php

namespace Tests\Feature;

use App\Events\ApprovalRequestChanged;
use App\Events\CustomerChanged;
use App\Events\InvoicePaymentRecorded;
use App\Events\ProductPriceChanged;
use App\Events\PurchaseOrderChanged;
use App\Events\ShiftStatusChanged;
use App\Events\StockLevelChanged;
use App\Events\StockTransferChanged;
use App\Events\TillCashMovementRecorded;
use App\Events\TransactionRecorded;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\Shift;
use App\Models\StockTransfer;
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
}
