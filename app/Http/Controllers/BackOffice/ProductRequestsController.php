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
     * Populated entirely by the till (staff log an ask whenever a customer
     * requests something not stocked) — this page is a read view of that
     * log plus a status toggle, no new stock/ledger logic of its own.
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
            ->get(['id', 'product_name', 'note', 'status', 'requested_by_user_id', 'created_at']);

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
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->apply($processor, (string) Str::uuid(), [
            'business_id' => $this->tenantId(),
            'requested_by_user_id' => session('backoffice')['user_id'] ?? null,
            'product_name' => $data['product_name'],
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
            'business_id' => $existing->business_id,
            'location_id' => $existing->location_id,
            'requested_by_user_id' => $existing->requested_by_user_id,
            'product_name' => $existing->product_name,
            'note' => $existing->note,
            'status' => $existing->status === 'open' ? 'fulfilled' : 'open',
            'created_at' => $existing->created_at?->toIso8601String(),
        ]);

        return back()->with('success', 'Request updated.');
    }

    public function destroy(string $productRequest, SyncProcessor $processor): RedirectResponse
    {
        $existing = ProductRequest::where('business_id', $this->tenantId())->findOrFail($productRequest);

        $this->apply($processor, $existing->id, [
            'business_id' => $existing->business_id,
            'location_id' => $existing->location_id,
            'requested_by_user_id' => $existing->requested_by_user_id,
            'product_name' => $existing->product_name,
            'note' => $existing->note,
            'status' => $existing->status,
            'created_at' => $existing->created_at?->toIso8601String(),
            'deleted_at' => now()->toIso8601String(),
        ]);

        return back()->with('success', 'Request removed.');
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
