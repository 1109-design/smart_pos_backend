<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\ProductRequest;
use App\Models\SyncRecord;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProductRequestsController extends Controller
{
    /**
     * Populated mainly by the till — StockRequestService on the Flutter side
     * logs the customer's name/phone and the item, then automatically flags
     * it `stock_available` the moment a receipt/adjustment/transfer/stocktake
     * brings it back, prompting a cashier to text the customer (see
     * smart_pos's lib/features/stock_requests). This page is a read view of
     * that log plus a manual open/fulfilled toggle and its own `store()` for
     * logging one from a phone call — no restock detection or SMS sending
     * lives here; that stays till-side per the Flutter-first architecture.
     * Open to every backoffice role, same as Dashboard/Transactions: the
     * point is letting whoever is on shift see what's being asked for.
     */
    public function index(Request $request): Response
    {
        $tenantId = $this->tenantId();

        $requests = ProductRequest::with('requestedBy:id,name')
            ->where('business_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get([
                'id', 'product_name', 'customer_name', 'customer_phone',
                'quantity', 'note', 'status', 'stock_available_at',
                'notified_at', 'requested_by_user_id', 'created_at',
            ]);

        $tally = $requests
            ->groupBy(fn (ProductRequest $r) => Str::lower(trim($r->product_name)))
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'product_name' => trim($first->product_name),
                    'total' => $group->count(),
                    'open_count' => $group->where('status', 'open')->count(),
                    'last_asked_at' => $group->max('created_at'),
                ];
            })
            ->sortByDesc('total')
            ->values();

        return Inertia::render('BackOffice/ProductRequests', [
            'requests' => $requests,
            'tally' => $tally,
        ]);
    }

    public function store(Request $request, SyncProcessor $processor): RedirectResponse
    {
        $data = $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'quantity' => ['nullable', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->apply($processor, (string) Str::uuid(), [
            'business_id' => $this->tenantId(),
            'requested_by_user_id' => session('backoffice')['user_id'] ?? null,
            'product_name' => $data['product_name'],
            'customer_name' => $data['customer_name'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => 'open',
            'created_at' => now()->toIso8601String(),
        ]);

        return back()->with('success', 'Request logged.');
    }

    public function toggleStatus(string $productRequest, SyncProcessor $processor): RedirectResponse
    {
        $existing = ProductRequest::where('business_id', $this->tenantId())->findOrFail($productRequest);

        $this->apply($processor, $existing->id, [
            ...$this->fullPayload($existing),
            'status' => $existing->status === 'open' ? 'fulfilled' : 'open',
        ]);

        return back()->with('success', 'Request updated.');
    }

    public function destroy(string $productRequest, SyncProcessor $processor): RedirectResponse
    {
        $existing = ProductRequest::where('business_id', $this->tenantId())->findOrFail($productRequest);

        $this->apply($processor, $existing->id, [
            ...$this->fullPayload($existing),
            'deleted_at' => now()->toIso8601String(),
        ]);

        return back()->with('success', 'Request removed.');
    }

    /**
     * Every column this table carries, as it stands right now — the base a
     * mutation (toggle/delete) layers its one change on top of. Without
     * this, re-applying through `process()`/`updateOrCreate()` (a full-row
     * replace keyed on the fields present in the payload) would silently
     * null out everything the till wrote — product_id, customer_name/phone,
     * stock_available_at, notified_at — since none of those ever passed
     * through a plain status toggle or soft delete before.
     *
     * @return array<string, mixed>
     */
    private function fullPayload(ProductRequest $existing): array
    {
        return [
            'business_id' => $existing->business_id,
            'location_id' => $existing->location_id,
            'product_id' => $existing->product_id,
            'requested_by_user_id' => $existing->requested_by_user_id,
            'product_name' => $existing->product_name,
            'customer_name' => $existing->customer_name,
            'customer_phone' => $existing->customer_phone,
            'quantity' => $existing->quantity,
            'note' => $existing->note,
            'status' => $existing->status,
            'stock_available_at' => $existing->stock_available_at?->toIso8601String(),
            'notified_by_user_id' => $existing->notified_by_user_id,
            'notified_at' => $existing->notified_at?->toIso8601String(),
            'sms_message_id' => $existing->sms_message_id,
            'created_at' => $existing->created_at?->toIso8601String(),
        ];
    }

    /**
     * Apply through the same SyncProcessor a device push uses, then publish
     * to the sync stream so every device receives it.
     *
     * @param  array<string, mixed>  $data
     */
    private function apply(SyncProcessor $processor, string $uuid, array $data): void
    {
        $processor->process('product_requests', $uuid, 'upsert', $data);

        SyncRecord::create([
            'business_id' => $data['business_id'],
            'table_name' => 'product_requests',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $data,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }

    private function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
    }
}
