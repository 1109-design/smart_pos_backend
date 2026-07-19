<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\SyncRecord;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProductsController extends Controller
{
    public function index(Request $request): Response
    {
        $typeFilter = $request->string('type')->toString();
        $search = $request->string('search')->toString();

        $products = Product::query()
            ->when($typeFilter === 'product' || $typeFilter === 'service',
                fn ($query) => $query->where('item_type', $typeFilter))
            ->when($search !== '', fn ($query) => $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->select([
                'id', 'name', 'item_type', 'sku', 'barcode', 'price', 'cost_price',
                'unit', 'track_stock', 'stock_quantity', 'low_stock_threshold',
                'category_id', 'is_active',
            ])
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('BackOffice/Products', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(['id', 'name', 'is_active']),
            'filters' => ['type' => $typeFilter ?: 'all', 'search' => $search],
        ]);
    }

    public function store(Request $request, SyncProcessor $processor): RedirectResponse
    {
        $data = $this->validatePayload($request);

        $this->applyThroughSyncPipeline($processor, (string) Str::uuid(), $data);

        return back()->with('success', 'Item created. Devices will receive it on their next sync.');
    }

    public function update(Request $request, string $product, SyncProcessor $processor): RedirectResponse
    {
        Product::findOrFail($product);
        $data = $this->validatePayload($request);

        $this->applyThroughSyncPipeline($processor, $product, $data);

        return back()->with('success', 'Item updated. Devices will receive it on their next sync.');
    }

    public function toggleActive(Request $request, string $product, SyncProcessor $processor): RedirectResponse
    {
        $existing = Product::findOrFail($product);

        $payload = $existing->only([
            'business_id', 'category_id', 'name', 'item_type', 'sku', 'barcode',
            'price', 'min_price', 'discount_percent', 'cost_price', 'unit',
            'track_stock', 'stock_quantity', 'low_stock_threshold', 'image_path',
        ]);
        $payload['is_active'] = ! $existing->is_active;

        $processor->process('products', $product, 'upsert', $payload);
        $this->publishSyncRecord($product, $payload);

        return back()->with('success', $payload['is_active'] ? 'Item restored.' : 'Item archived.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'item_type' => ['required', 'in:product,service'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'string', 'exists:categories,id'],
            'unit' => ['nullable', 'string', 'max:30'],
            'track_stock' => ['boolean'],
            'stock_quantity' => ['nullable', 'numeric', 'min:0'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $isService = $validated['item_type'] === 'service';

        return [
            'category_id' => $validated['category_id'] ?? null,
            'name' => $validated['name'],
            'item_type' => $validated['item_type'],
            'sku' => $validated['sku'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'price' => $validated['price'],
            'cost_price' => $validated['cost_price'] ?? 0,
            'unit' => $isService ? 'service' : ($validated['unit'] ?? 'piece'),
            'track_stock' => $isService ? false : ($validated['track_stock'] ?? true),
            'stock_quantity' => $isService ? 0 : ($validated['stock_quantity'] ?? 0),
            'low_stock_threshold' => $validated['low_stock_threshold'] ?? 5,
            'is_active' => $validated['is_active'] ?? true,
        ];
    }

    /**
     * Run the write through the same SyncProcessor a device push uses (so
     * opening stock gets a ledger entry and totals recompute identically),
     * then publish it to the sync stream for every device to pull.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyThroughSyncPipeline(SyncProcessor $processor, string $uuid, array $data): void
    {
        $session = session('backoffice');
        $data['business_id'] = $session['tenant_id'] ?? null;

        $processor->process('products', $uuid, 'upsert', $data);
        $this->publishSyncRecord($uuid, $data);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publishSyncRecord(string $uuid, array $payload): void
    {
        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? session('backoffice')['tenant_id'] ?? null,
            'table_name' => 'products',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
