<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\BundleItem;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BundlesController extends Controller
{
    public function index(): Response
    {
        $tenantId = $this->tenantId();

        $bundles = Bundle::with('items.product:id,name,item_type,price')
            ->where('business_id', $tenantId)
            ->orderBy('name')
            ->get()
            ->map(fn (Bundle $bundle) => [
                'id' => $bundle->id,
                'name' => $bundle->name,
                'description' => $bundle->description,
                'is_active' => $bundle->is_active,
                'items' => $bundle->items->map(fn (BundleItem $item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => (float) $item->quantity,
                    'name' => $item->product?->name ?? 'Deleted item',
                    'item_type' => $item->product?->item_type ?? 'product',
                    'price' => (float) ($item->product?->price ?? 0),
                ]),
            ]);

        return Inertia::render('BackOffice/Bundles', [
            'bundles' => $bundles,
            'catalog' => Product::where('business_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'item_type', 'price']),
        ]);
    }

    public function store(Request $request, SyncProcessor $processor): RedirectResponse
    {
        $data = $this->validatePayload($request);

        $this->applyBundle($processor, (string) Str::uuid(), $data, existing: null);

        return back()->with('success', 'Combo created. Devices will receive it on their next sync.');
    }

    public function update(Request $request, string $bundle, SyncProcessor $processor): RedirectResponse
    {
        $existing = Bundle::with('items')->where('business_id', $this->tenantId())->findOrFail($bundle);
        $data = $this->validatePayload($request);

        $this->applyBundle($processor, $bundle, $data, $existing);

        return back()->with('success', 'Combo updated. Devices will receive it on their next sync.');
    }

    public function toggleActive(string $bundle, SyncProcessor $processor): RedirectResponse
    {
        $existing = Bundle::where('business_id', $this->tenantId())->findOrFail($bundle);

        $payload = [
            'business_id' => $existing->business_id,
            'name' => $existing->name,
            'description' => $existing->description,
            'is_active' => ! $existing->is_active,
        ];

        $processor->process('bundles', $existing->id, 'upsert', $payload);
        $this->publishSyncRecord('bundles', $existing->id, $payload);

        return back()->with('success', $payload['is_active'] ? 'Combo restored.' : 'Combo archived.');
    }

    /**
     * @return array{name: string, description: string|null, items: array<int, array{product_id: string, quantity: float}>}
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
        ]);
    }

    /**
     * Upsert the bundle and replace its items, mirroring everything into the
     * sync stream so every device (including newly paired ones) receives it.
     *
     * @param  array{name: string, description?: string|null, items: array<int, array{product_id: string, quantity: float|int|string}>}  $data
     */
    private function applyBundle(SyncProcessor $processor, string $bundleId, array $data, ?Bundle $existing): void
    {
        $businessId = $this->tenantId();

        $bundlePayload = [
            'business_id' => $businessId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => true,
        ];

        $processor->process('bundles', $bundleId, 'upsert', $bundlePayload);
        $this->publishSyncRecord('bundles', $bundleId, $bundlePayload);

        // Replace items: delete rows that are no longer present, then upsert
        // the new set. Deletions are mirrored as sync deletes.
        if ($existing) {
            foreach ($existing->items as $oldItem) {
                $processor->process('bundle_items', $oldItem->id, 'delete', ['business_id' => $businessId]);
                SyncRecord::create([
                    'business_id' => $businessId,
                    'table_name' => 'bundle_items',
                    'record_uuid' => $oldItem->id,
                    'operation' => 'delete',
                    'payload' => [],
                    'source_updated_at' => now(),
                    'synced_at' => now(),
                ]);
            }
        }

        foreach (array_values($data['items']) as $index => $item) {
            $itemId = (string) Str::uuid();
            $itemPayload = [
                'business_id' => $businessId,
                'bundle_id' => $bundleId,
                'product_id' => $item['product_id'],
                'quantity' => (float) $item['quantity'],
                'sort_order' => $index,
            ];
            $processor->process('bundle_items', $itemId, 'upsert', $itemPayload);
            $this->publishSyncRecord('bundle_items', $itemId, $itemPayload);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publishSyncRecord(string $table, string $uuid, array $payload): void
    {
        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? $this->tenantId(),
            'table_name' => $table,
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }

    private function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
    }
}
