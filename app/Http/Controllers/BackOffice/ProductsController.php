<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Device;
use App\Models\Product;
use App\Models\SyncCursor;
use App\Models\SyncRecord;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductsController extends Controller
{
    /** Column order for the downloadable import template, and the CSV columns import() reads. */
    private const IMPORT_COLUMNS = [
        'name', 'item_type', 'price', 'cost_price', 'sku', 'barcode',
        'category', 'unit', 'track_stock', 'stock_quantity', 'low_stock_threshold',
    ];

    private const IMPORT_ROW_LIMIT = 2000;

    public function index(Request $request): Response
    {
        $typeFilter = $request->string('type')->toString();
        $search = $request->string('search')->toString();
        $tenantId = $this->tenantId();

        $products = Product::query()
            ->where('business_id', $tenantId)
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

        $this->attachSyncStatus($products);

        return Inertia::render('BackOffice/Products', [
            'products' => $products,
            'categories' => Category::where('business_id', $tenantId)->orderBy('name')->get(['id', 'name', 'is_active']),
            'filters' => ['type' => $typeFilter ?: 'all', 'search' => $search],
        ]);
    }

    /** A ready-to-fill CSV: header row + two worked examples (product and service). */
    public function template(): StreamedResponse
    {
        $rows = [
            self::IMPORT_COLUMNS,
            ['Coca Cola 500ml', 'product', '1.50', '0.90', 'COKE500', '6001234567890', 'Beverages', 'piece', 'yes', '50', '10'],
            ['Phone Screen Repair', 'service', '25.00', '', '', '', 'Repairs', '', 'no', '', ''],
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'products-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Bulk create/update items from an uploaded CSV built from template().
     * Matching an existing item is by SKU, then barcode; unmatched rows create
     * a new item. Every write goes through the same sync pipeline as the
     * manual form, so devices pick it up on their next pull.
     */
    public function import(Request $request, SyncProcessor $processor): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $tenantId = $this->tenantId();

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = $handle ? fgetcsv($handle) : false;

        if ($header === false) {
            return back()->withErrors(['file' => 'The file could not be read or is empty.']);
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        foreach (['name', 'item_type', 'price'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($handle);

                return back()->withErrors(['file' => "Missing required column \"{$required}\". Download the template for the expected format."]);
            }
        }

        $categoryIdsByName = Category::where('business_id', $tenantId)->get(['id', 'name'])
            ->mapWithKeys(fn ($category) => [strtolower(trim($category->name)) => $category->id]);

        $created = 0;
        $updated = 0;
        $errors = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($rowNumber - 1 > self::IMPORT_ROW_LIMIT) {
                $errors[] = 'Stopped after '.self::IMPORT_ROW_LIMIT.' rows — split larger catalogues into multiple files.';
                break;
            }

            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $data = [];
            foreach ($header as $index => $key) {
                $data[$key] = trim((string) ($row[$index] ?? ''));
            }

            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'item_type' => ['required', 'in:product,service'],
                'price' => ['required', 'numeric', 'min:0'],
                'cost_price' => ['nullable', 'numeric', 'min:0'],
                'sku' => ['nullable', 'string', 'max:100'],
                'barcode' => ['nullable', 'string', 'max:100'],
                'unit' => ['nullable', 'string', 'max:30'],
                'stock_quantity' => ['nullable', 'numeric', 'min:0'],
                'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNumber}: ".implode(' ', $validator->errors()->all());

                continue;
            }

            $valid = $validator->validated();
            $isService = $valid['item_type'] === 'service';

            $existing = null;
            if (($valid['sku'] ?? '') !== '') {
                $existing = Product::where('business_id', $tenantId)->where('sku', $valid['sku'])->first();
            }
            if (! $existing && ($valid['barcode'] ?? '') !== '') {
                $existing = Product::where('business_id', $tenantId)->where('barcode', $valid['barcode'])->first();
            }

            $categoryId = null;
            if (($data['category'] ?? '') !== '') {
                $categoryId = $categoryIdsByName->get(strtolower($data['category']));
                if (! $categoryId) {
                    $errors[] = "Row {$rowNumber}: category \"{$data['category']}\" not found — item saved without a category.";
                }
            }

            $payload = [
                'category_id' => $categoryId,
                'name' => $valid['name'],
                'item_type' => $valid['item_type'],
                'sku' => ($valid['sku'] ?? '') !== '' ? $valid['sku'] : null,
                'barcode' => ($valid['barcode'] ?? '') !== '' ? $valid['barcode'] : null,
                'price' => $valid['price'],
                'cost_price' => $valid['cost_price'] ?? 0,
                'unit' => $isService ? 'service' : (($data['unit'] ?? '') !== '' ? $data['unit'] : 'piece'),
                'track_stock' => $isService ? false : $this->parseBoolean($data['track_stock'] ?? '', true),
                'low_stock_threshold' => $valid['low_stock_threshold'] ?? 5,
            ];

            if ($existing) {
                // Stock is ledger-owned after creation — same rule the manual edit form
                // follows (it disables the field and resubmits the current value).
                $payload['stock_quantity'] = (float) $existing->stock_quantity;
                $payload['is_active'] = $existing->is_active;
                $this->applyThroughSyncPipeline($processor, $existing->id, $payload);
                $updated++;
            } else {
                $payload['stock_quantity'] = $isService ? 0 : ($valid['stock_quantity'] ?? 0);
                $payload['is_active'] = true;
                $this->applyThroughSyncPipeline($processor, (string) Str::uuid(), $payload);
                $created++;
            }
        }

        fclose($handle);

        $summary = "Imported {$created} new and updated {$updated} existing item(s). Devices will receive the changes on their next sync.";
        if ($errors) {
            $summary .= ' '.count($errors).' row(s) need attention — see details below.';
        }

        return back()->with('success', $summary)->with('import_errors', $errors ?: null);
    }

    private function parseBoolean(string $value, bool $default): bool
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return $default;
        }

        return in_array($value, ['yes', 'y', 'true', '1'], true);
    }

    /** Devices that haven't checked in for longer than this are treated as abandoned, not "pending sync". */
    private const ACTIVE_DEVICE_WINDOW_DAYS = 14;

    /**
     * Approximate each product's device-sync status: how many of the
     * business's active devices have pulled a 'products' change at or after
     * this product's latest sync_records entry. Uses the per-table pull
     * watermark in sync_cursors (not a per-record delivery receipt), and
     * treats the device that originated a write as already having it, since
     * SyncController::pull() excludes a device's own writes from its feed.
     *
     * "Active" excludes devices unseen for ACTIVE_DEVICE_WINDOW_DAYS — an old
     * till that was paired once and abandoned should not sit in the
     * denominator forever making every item look permanently unsynced.
     */
    private function attachSyncStatus(LengthAwarePaginator $products): void
    {
        $tenantId = $this->tenantId();

        $deviceIds = Device::where('tenant_id', $tenantId)
            ->where('is_revoked', false)
            ->where('last_seen_at', '>=', now()->subDays(self::ACTIVE_DEVICE_WINDOW_DAYS))
            ->pluck('id');
        $totalDevices = $deviceIds->count();

        $cursors = SyncCursor::whereIn('device_id', $deviceIds)
            ->where('table_name', 'products')
            ->pluck('last_pulled_at', 'device_id');

        $latestSyncByProduct = SyncRecord::query()
            ->where('business_id', $tenantId)
            ->where('table_name', 'products')
            ->whereIn('record_uuid', $products->getCollection()->pluck('id'))
            ->orderByDesc('synced_at')
            ->get(['record_uuid', 'synced_at', 'device_id'])
            ->unique('record_uuid')
            ->keyBy('record_uuid');

        $products->getCollection()->transform(function (Product $product) use ($latestSyncByProduct, $cursors, $deviceIds, $totalDevices) {
            $sync = $latestSyncByProduct->get($product->id);

            $product->total_devices = $totalDevices;
            $product->synced_devices = $sync
                ? $deviceIds->filter(fn ($deviceId) => $this->deviceHasSynced($deviceId, $sync, $cursors))->count()
                : null;

            return $product;
        });
    }

    private function deviceHasSynced(int $deviceId, SyncRecord $sync, Collection $cursors): bool
    {
        if ($sync->device_id !== null && (int) $sync->device_id === $deviceId) {
            return true;
        }

        $lastPulled = $cursors->get($deviceId);

        return $lastPulled && $lastPulled->greaterThanOrEqualTo($sync->synced_at);
    }

    public function store(Request $request, SyncProcessor $processor): RedirectResponse
    {
        $data = $this->validatePayload($request);

        $this->applyThroughSyncPipeline($processor, (string) Str::uuid(), $data);

        return back()->with('success', 'Item created. Devices will receive it on their next sync.');
    }

    public function update(Request $request, string $product, SyncProcessor $processor): RedirectResponse
    {
        Product::where('business_id', $this->tenantId())->findOrFail($product);
        $data = $this->validatePayload($request);

        $this->applyThroughSyncPipeline($processor, $product, $data);

        return back()->with('success', 'Item updated. Devices will receive it on their next sync.');
    }

    public function toggleActive(Request $request, string $product, SyncProcessor $processor): RedirectResponse
    {
        $existing = Product::where('business_id', $this->tenantId())->findOrFail($product);

        // Built as plain literals, not $existing->only(): Product casts every
        // price/quantity field as decimal:N, which Eloquent renders as a
        // STRING (e.g. "120.0000") to avoid float precision loss. Put through
        // ->only() unchanged, that string lands in the sync payload's JSON
        // and breaks the device's `as num?` cast on pull. Casting back to
        // float/int here keeps the payload numeric on the wire.
        $payload = [
            'business_id' => $existing->business_id,
            'category_id' => $existing->category_id,
            'name' => $existing->name,
            'item_type' => $existing->item_type,
            'sku' => $existing->sku,
            'barcode' => $existing->barcode,
            'price' => (float) $existing->price,
            'min_price' => $existing->min_price !== null ? (float) $existing->min_price : null,
            'discount_percent' => $existing->discount_percent !== null ? (float) $existing->discount_percent : null,
            'cost_price' => (float) $existing->cost_price,
            'unit' => $existing->unit,
            'track_stock' => (bool) $existing->track_stock,
            'stock_quantity' => (float) $existing->stock_quantity,
            'low_stock_threshold' => (float) $existing->low_stock_threshold,
            'image_path' => $existing->image_path,
            'is_active' => ! $existing->is_active,
        ];

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
        $data['business_id'] = $this->tenantId();

        $processor->process('products', $uuid, 'upsert', $data);
        $this->publishSyncRecord($uuid, $data);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publishSyncRecord(string $uuid, array $payload): void
    {
        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? $this->tenantId(),
            'table_name' => 'products',
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
