<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\PoAuditLog;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SyncRecord;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrdersController extends Controller
{
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

        $orders = PurchaseOrder::with(['receivingLocation:id,name'])
            ->where('business_id', $tenantId)
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

        $order = PurchaseOrder::with(['receivingLocation:id,name', 'items'])
            ->where('business_id', $this->tenantId())
            ->findOrFail($purchaseOrder);

        $audit = PoAuditLog::where('po_id', $order->id)->latest('created_at')->get();

        return Inertia::render('BackOffice/PurchaseOrderShow', [
            'order' => $order,
            'audit' => $audit,
        ]);
    }

    public function cancel(string $purchaseOrder, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $order = PurchaseOrder::where('business_id', $this->tenantId())->findOrFail($purchaseOrder);

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

    private function authorizeManager(): void
    {
        abort_if(
            ! in_array(session('backoffice.role'), ['business_owner', 'manager']),
            403,
            'Access denied.'
        );
    }

    private function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
    }

    private function userId(): ?string
    {
        return session('backoffice')['user_id'] ?? null;
    }
}
