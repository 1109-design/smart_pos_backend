<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Accounting\JournalHeader;
use App\Models\GoodsReceivedVoucher;
use App\Models\PoAuditLog;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SyncRecord;
use App\Services\BackOfficeAuthorizer;
use App\Services\SyncProcessor;
use App\Support\BackOfficePermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrdersController extends BackOfficeController
{
    public function __construct(private readonly BackOfficeAuthorizer $authorizer) {}

    /**
     * Purchase orders are created and received at the till, where goods are
     * physically handled — this page is visibility from the web plus a
     * cancel action for a draft/sent order an owner wants to kill without
     * walking up to a device.
     */
    public function index(Request $request): Response
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();
        $status = $request->string('status')->toString() ?: 'all';
        $supplierId = $request->string('supplier')->toString() ?: 'all';

        $orders = $this->scopedOrders()
            ->with('receivingLocation:id,name')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($supplierId !== 'all', fn ($q) => $q->where('supplier_id', $supplierId))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('BackOffice/PurchaseOrders', [
            'orders' => $orders,
            'suppliers' => Supplier::where('business_id', $tenantId)->orderBy('name')->get(['id', 'name']),
            'filters' => ['status' => $status, 'supplier' => $supplierId],
        ]);
    }

    public function show(string $purchaseOrder): Response
    {
        $this->authorizeManager();

        $order = $this->scopedOrders()
            ->with(['receivingLocation:id,name', 'items'])
            ->findOrFail($purchaseOrder);

        $audit = PoAuditLog::where('po_id', $order->id)->latest('created_at')->get();

        // Purchasing & Cash Vault Blueprint, part A — GRVs are created
        // automatically by GrvPostingService whenever a synced receipt
        // references this PO; nothing here creates one. Each GRV's posted
        // status shows whether it actually reached the general ledger.
        $grvs = GoodsReceivedVoucher::where('purchase_order_id', $order->id)
            ->with('items')
            ->orderByDesc('received_date')
            ->get()
            ->map(function (GoodsReceivedVoucher $grv) {
                $posted = JournalHeader::where('source_type', 'grv')
                    ->where('source_id', $grv->id)
                    ->where('status', 'posted')
                    ->exists();

                // Part B — has the supplier's actual bill been recorded
                // against this voucher yet? Drives whether the page shows
                // an invoice-entry form or the invoice that's already there.
                $invoice = SupplierInvoice::where('grv_id', $grv->id)->first();

                return [
                    'id' => $grv->id,
                    'grv_number' => $grv->grv_number,
                    'received_date' => $grv->received_date->toDateString(),
                    'posted_to_ledger' => $posted,
                    'value' => (float) $grv->items->sum(fn ($i) => (float) $i->quantity_accepted * (float) $i->unit_cost),
                    'invoice' => $invoice ? [
                        'invoice_number' => $invoice->invoice_number,
                        'invoice_date' => $invoice->invoice_date->toDateString(),
                        'amount' => (float) $invoice->amount,
                    ] : null,
                    'items' => $grv->items->map(fn ($item) => [
                        'product_name' => $item->product_name,
                        'quantity_received' => (float) $item->quantity_received,
                        'quantity_accepted' => (float) $item->quantity_accepted,
                        'quantity_rejected' => (float) $item->quantity_rejected,
                        'unit_cost' => (float) $item->unit_cost,
                        'landed_unit_cost' => $item->landed_unit_cost !== null ? (float) $item->landed_unit_cost : null,
                    ]),
                ];
            });

        return Inertia::render('BackOffice/PurchaseOrderShow', [
            'order' => $order,
            'audit' => $audit,
            'grvs' => $grvs,
        ]);
    }

    public function cancel(string $purchaseOrder, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $order = $this->scopedOrders()->findOrFail($purchaseOrder);

        abort_if(! in_array($order->status, ['draft', 'sent'], true), 422, 'Only a draft or sent order can be cancelled from here.');

        $payload = [
            'business_id' => $order->business_id,
            'receiving_location_id' => $order->receiving_location_id,
            'supplier_id' => $order->supplier_id,
            'supplier_name' => $order->supplier_name,
            'po_number' => $order->po_number,
            'status' => 'cancelled',
            'notes' => $order->notes,
            'expected_date' => $order->expected_date?->toIso8601String(),
            'additional_costs_json' => $order->additional_costs_json,
            'created_by_user_id' => $order->created_by_user_id,
        ];

        $processor->process('purchase_orders', $order->id, 'upsert', $payload);

        SyncRecord::create([
            'business_id' => $order->business_id,
            'table_name' => 'purchase_orders',
            'record_uuid' => $order->id,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);

        PoAuditLog::create([
            'po_id' => $order->id,
            'user_id' => $this->userId(),
            'user_name' => session('backoffice.user_name'),
            'action' => 'cancelled',
            'note' => 'Cancelled from Back Office.',
        ]);

        return redirect()->route('office.purchase-orders.index')->with('success', "{$order->po_number} cancelled.");
    }

    /**
     * Base query every action uses: this tenant's orders, further narrowed
     * to the acting user's location scope when they're restricted to
     * specific branches. Centralized so a new action (like cancel(), which
     * previously skipped this) can't forget to apply it.
     */
    private function scopedOrders(): Builder
    {
        $scope = $this->authorizer->currentLocationScope();

        return PurchaseOrder::where('business_id', $this->tenantId())
            ->when($scope !== null, fn ($q) => $q->whereIn('receiving_location_id', $scope));
    }

    private function authorizeManager(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_PURCHASE_ORDERS),
            403,
            'Access denied.'
        );
    }
}
