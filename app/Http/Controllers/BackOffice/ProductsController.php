<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Device;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductContainerLink;
use App\Models\ProductStock;
use App\Models\SyncCursor;
use App\Models\SyncRecord;
use App\Services\LocationService;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    public function index(Request $request, LocationService $locations): Response
    {
        $typeFilter = $request->string('type')->toString();
        $search = $request->string('search')->toString();
        $tenantId = $this->tenantId();

        // Guarantees a location exists so the create form always has
        // somewhere to put opening stock, even for businesses that never
        // touched multi-location settings.
        $defaultLocation = $locations->ensureDefaultLocation($tenantId);

        $products = Product::query()
            ->where('business_id', $tenantId)
            ->when(in_array($typeFilter, ['product', 'service', 'container'], true),
                fn ($query) => $query->where('item_type', $typeFilter))
            ->when($search !== '', fn ($query) => $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->select([
                'id', 'name', 'item_type', 'sku', 'barcode', 'price', 'cost_price',
                'min_price', 'discount_percent', 'deposit_amount', 'expiry_date',
                'unit', 'track_stock', 'stock_quantity', 'low_stock_threshold',
                'category_id', 'is_active',
            ])
            ->paginate(25)
            ->withQueryString();

        $this->attachSyncStatus($products);
        $this->attachContainerLinks($products);

        $activeLocations = Location::where('business_id', $tenantId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']);

        // Only worth the query for businesses actually using more than one
        // location — a single-location business's flat stock_quantity
        // already tells the whole story.
        if ($activeLocations->count() > 1) {
            $this->attachLocationStock($products);
        }

        return Inertia::render('BackOffice/Products', [
            'products' => $products,
            'categories' => Category::where('business_id', $tenantId)->orderBy('name')->get(['id', 'name', 'is_active']),
            'locations' => $activeLocations,
            'default_location_id' => $defaultLocation->id,
            // For the returnable-packaging picker on a 'product' item — every
            // active container this business has defined, deposit included so
            // the picker can show it the way the mobile form does.
            'containers' => Product::where('business_id', $tenantId)
                ->where('item_type', 'container')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'deposit_amount']),
            'filters' => ['type' => $typeFilter ?: 'all', 'search' => $search],
        ]);
    }

    /**
     * Attach each 'product'-type row's current returnable-packaging links
     * (which container(s) it carries when sold, and the qty of each) so the
     * edit form can prefill them — mirrors attachLocationStock() below.
     */
    private function attachContainerLinks(LengthAwarePaginator $products): void
    {
        $productIds = $products->getCollection()
            ->filter(fn (Product $product) => $product->item_type === 'product')
            ->pluck('id');

        if ($productIds->isEmpty()) {
            return;
        }

        $links = ProductContainerLink::whereIn('beverage_product_id', $productIds)
            ->get(['id', 'beverage_product_id', 'container_product_id', 'quantity_per_unit'])
            ->groupBy('beverage_product_id');

        $products->getCollection()->transform(function (Product $product) use ($links) {
            $product->container_links = ($links->get($product->id) ?? collect())
                ->map(fn (ProductContainerLink $link) => [
                    'id' => $link->id,
                    'container_product_id' => $link->container_product_id,
                    'quantity_per_unit' => (float) $link->quantity_per_unit,
                ])
                ->values();

            return $product;
        });
    }

    /**
     * Attach each product's per-location stock split (from the `product_stock`
     * ledger view) so the owner can see where inventory actually sits instead
     * of only the flat cross-location total. Locations a product has never had
     * a ledger entry at (e.g. created before multi-location, or before its
     * first stock movement at that location) simply don't appear.
     */
    private function attachLocationStock(LengthAwarePaginator $products): void
    {
        $productIds = $products->getCollection()
            ->filter(fn (Product $product) => $product->item_type !== 'service' && $product->track_stock)
            ->pluck('id');

        if ($productIds->isEmpty()) {
            return;
        }

        $rows = ProductStock::whereIn('product_id', $productIds)
            ->join('locations', 'product_stock.location_id', '=', 'locations.id')
            ->orderBy('locations.name')
            ->get([
                'product_stock.product_id', 'product_stock.location_id', 'product_stock.quantity',
                'product_stock.reserved_quantity', 'locations.name as location_name',
            ])
            ->groupBy('product_id');

        $products->getCollection()->transform(function (Product $product) use ($rows) {
            $product->stock_by_location = ($rows->get($product->id) ?? collect())
                ->map(fn ($row) => [
                    'location_id' => $row->location_id,
                    'location_name' => $row->location_name,
                    'quantity' => (float) $row->quantity,
                    'reserved_quantity' => (float) $row->reserved_quantity,
                ])
                ->values();

            return $product;
        });
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
    public function import(Request $request, SyncProcessor $processor, LocationService $locations): RedirectResponse
    {
        $tenantId = $this->tenantId();

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'location_id' => ['nullable', 'string', Rule::exists('locations', 'id')->where('business_id', $tenantId)],
        ]);

        // The whole file lands at one location — the owner picks it in the
        // import dialog (defaulting to the business's default location) since
        // a CSV row has no natural place to carry that per-item.
        $locationId = $request->string('location_id')->toString() ?: $locations->ensureDefaultLocation($tenantId)->id;

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
                // The CSV format has no columns for these — without carrying
                // the existing values through, the full-row sync overwrite
                // below would silently null them out on every re-import.
                // deposit_amount is the one exception: the item_type rule
                // above only ever accepts product/service (never container),
                // so a CSV-touched row must never carry a deposit forward —
                // matching validatePayload()'s $isContainer ternary, which
                // enforces that non-container items always have a null
                // deposit_amount.
                $payload['min_price'] = $existing->min_price !== null ? (float) $existing->min_price : null;
                $payload['discount_percent'] = $existing->discount_percent !== null ? (float) $existing->discount_percent : null;
                $payload['deposit_amount'] = null;
                $payload['expiry_date'] = $existing->expiry_date?->toIso8601String();
                $this->applyThroughSyncPipeline($processor, $existing->id, $payload);
                $updated++;
            } else {
                $payload['stock_quantity'] = $isService ? 0 : ($valid['stock_quantity'] ?? 0);
                $payload['location_id'] = $isService ? null : $locationId;
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

    public function store(Request $request, SyncProcessor $processor, LocationService $locations): RedirectResponse
    {
        $data = $this->validatePayload($request);
        $containerLinks = $data['container_links'];
        unset($data['container_links']);

        // Opening stock has to land somewhere. If the form didn't specify a
        // location (or this is a service, where it's moot), fall back to the
        // business's default rather than leaving it unattributed.
        if ($data['track_stock'] && empty($data['location_id'])) {
            $data['location_id'] = $locations->ensureDefaultLocation($this->tenantId())->id;
        }

        $uuid = (string) Str::uuid();
        $this->applyThroughSyncPipeline($processor, $uuid, $data);
        $this->applyContainerLinks($processor, $uuid, $containerLinks, existingLinks: collect());

        return back()->with('success', 'Item created. Devices will receive it on their next sync.');
    }

    public function update(Request $request, string $product, SyncProcessor $processor): RedirectResponse
    {
        $existing = Product::where('business_id', $this->tenantId())->findOrFail($product);
        $data = $this->validatePayload($request, $existing);
        $containerLinks = $data['container_links'];
        unset($data['container_links']);

        $this->applyThroughSyncPipeline($processor, $product, $data);
        $this->applyContainerLinks(
            $processor,
            $product,
            $containerLinks,
            existingLinks: ProductContainerLink::where('beverage_product_id', $product)->get()
        );

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
            'deposit_amount' => $existing->deposit_amount !== null ? (float) $existing->deposit_amount : null,
            'unit' => $existing->unit,
            'track_stock' => (bool) $existing->track_stock,
            'stock_quantity' => (float) $existing->stock_quantity,
            'low_stock_threshold' => (float) $existing->low_stock_threshold,
            'image_path' => $existing->image_path,
            'expiry_date' => $existing->expiry_date?->toIso8601String(),
            'is_active' => ! $existing->is_active,
        ];

        $processor->process('products', $product, 'upsert', $payload);
        $this->publishSyncRecord($product, $payload);

        return back()->with('success', $payload['is_active'] ? 'Item restored.' : 'Item archived.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?Product $existing = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'item_type' => ['required', 'in:product,service,container'],
            'price' => ['required_unless:item_type,container', 'nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'string', 'exists:categories,id'],
            'unit' => ['nullable', 'string', 'max:30'],
            'track_stock' => ['boolean'],
            'stock_quantity' => ['nullable', 'numeric', 'min:0'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'expiry_date' => ['nullable', 'date'],
            'location_id' => ['nullable', 'string', Rule::exists('locations', 'id')->where('business_id', $this->tenantId())],
            'is_active' => ['boolean'],
            // Returnable packaging: containers (by id) this product carries
            // when sold, and how many of each per unit. Only meaningful for
            // item_type=product — see applyContainerLinks().
            'container_links' => ['nullable', 'array'],
            'container_links.*.container_product_id' => [
                'required_with:container_links',
                'string',
                // Without this, the same container can be linked twice to one
                // beverage, doubling the deposit charged whenever it's sold.
                'distinct',
                Rule::exists('products', 'id')->where('business_id', $this->tenantId())->where('item_type', 'container'),
            ],
            'container_links.*.quantity_per_unit' => ['required_with:container_links', 'numeric', 'min:0.0001'],
        ]);

        $isService = $validated['item_type'] === 'service';
        $isContainer = $validated['item_type'] === 'container';

        // The current BackOffice form always sends these four, but they're
        // easy to leave out of a future minimal integration (as the old CSV
        // importer and toggleActive() payloads once did) — falling back to
        // the existing row rather than defaulting to null means an update
        // can never silently wipe them just by omitting the field. Only
        // matters for update() (store() has no existing row).
        $minPrice = $request->has('min_price')
            ? ($validated['min_price'] ?? null)
            : ($existing?->min_price !== null ? (float) $existing->min_price : null);
        $discountPercent = $request->has('discount_percent')
            ? ($validated['discount_percent'] ?? null)
            : ($existing?->discount_percent !== null ? (float) $existing->discount_percent : null);
        $depositAmount = $request->has('deposit_amount')
            ? ($validated['deposit_amount'] ?? null)
            : ($existing?->deposit_amount !== null ? (float) $existing->deposit_amount : null);
        $expiryDate = $request->has('expiry_date')
            ? ($validated['expiry_date'] ?? null)
            : $existing?->expiry_date?->toIso8601String();

        return [
            'category_id' => $validated['category_id'] ?? null,
            'name' => $validated['name'],
            'item_type' => $validated['item_type'],
            'sku' => $validated['sku'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            // Containers aren't sold at a price — they carry a refundable
            // deposit instead (see deposit_amount below), mirroring the
            // mobile app's product_form_screen.dart.
            'price' => $isContainer ? 0 : $validated['price'],
            'min_price' => $isContainer ? null : $minPrice,
            'discount_percent' => $isContainer ? null : $discountPercent,
            'cost_price' => $validated['cost_price'] ?? 0,
            'deposit_amount' => $isContainer ? ($depositAmount ?? 0) : null,
            'unit' => $isService ? 'service' : ($validated['unit'] ?? 'piece'),
            'track_stock' => $isService ? false : ($validated['track_stock'] ?? true),
            'stock_quantity' => $isService ? 0 : ($validated['stock_quantity'] ?? 0),
            'low_stock_threshold' => $validated['low_stock_threshold'] ?? 5,
            'expiry_date' => $isService ? null : $expiryDate,
            // Only meaningful on create — see store(); update() leaves stock
            // untouched and never sends this field.
            'location_id' => $isService ? null : ($validated['location_id'] ?? null),
            'is_active' => $validated['is_active'] ?? true,
            // Stripped off in store()/update() before the product payload is
            // built — not a Product column, handled by applyContainerLinks().
            'container_links' => $isContainer ? [] : ($validated['container_links'] ?? []),
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
     * Replace a beverage product's returnable-packaging links: delete every
     * existing link, then upsert the submitted set, mirroring both into the
     * sync stream so every device (including newly paired ones) receives the
     * change — same delete-then-recreate pattern as BundlesController's
     * bundle_items, and the mobile app's own product_form_screen.dart.
     *
     * @param  array<int, array{container_product_id: string, quantity_per_unit: float|int|string}>  $links
     * @param  Collection<int, ProductContainerLink>  $existingLinks
     */
    private function applyContainerLinks(SyncProcessor $processor, string $beverageProductId, array $links, Collection $existingLinks): void
    {
        $businessId = $this->tenantId();

        foreach ($existingLinks as $old) {
            $processor->process('product_container_links', $old->id, 'delete', ['business_id' => $businessId]);
            $this->publishSyncRecord($old->id, [], table: 'product_container_links', operation: 'delete');
        }

        foreach ($links as $link) {
            $linkId = (string) Str::uuid();
            $linkPayload = [
                'business_id' => $businessId,
                'beverage_product_id' => $beverageProductId,
                'container_product_id' => $link['container_product_id'],
                'quantity_per_unit' => (float) $link['quantity_per_unit'],
            ];
            $processor->process('product_container_links', $linkId, 'upsert', $linkPayload);
            $this->publishSyncRecord($linkId, $linkPayload, table: 'product_container_links');
        }
    }

    /**
     * Mirrors a write into the sync stream so every device (including newly
     * paired ones) receives it — same generalized shape as
     * BundlesController::publishSyncRecord(), extended with an $operation
     * parameter so the delete case above doesn't need its own hand-rolled
     * SyncRecord::create().
     *
     * @param  array<string, mixed>  $payload
     */
    private function publishSyncRecord(string $uuid, array $payload, string $table = 'products', string $operation = 'upsert'): void
    {
        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? $this->tenantId(),
            'table_name' => $table,
            'record_uuid' => $uuid,
            'operation' => $operation,
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
