<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Device;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductContainerLink;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\SyncCursor;
use App\Models\SyncRecord;
use App\Services\LocationService;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductsController extends Controller
{
    /** Column order for the downloadable import template, before the stock column(s) — see template(). */
    private const IMPORT_BASE_COLUMNS = [
        'name', 'item_type', 'price', 'cost_price', 'sku', 'barcode', 'category', 'unit', 'track_stock',
    ];

    private const IMPORT_ROW_LIMIT = 2000;

    public function index(Request $request, LocationService $locations): Response
    {
        $typeFilter = $request->string('type')->toString();
        $statusFilter = $request->string('status')->toString() ?: 'active';
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
            // Archived items are opt-in via the Archived/All tabs — an
            // archived duplicate sitting silently in the default list is
            // exactly the confusion a merge/archive is meant to clear up.
            ->when($statusFilter === 'active', fn ($query) => $query->where('is_active', true))
            ->when($statusFilter === 'archived', fn ($query) => $query->where('is_active', false))
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
                'category_id', 'is_active', 'merged_into_product_id',
            ])
            ->paginate(25)
            ->withQueryString();

        $this->attachSyncStatus($products);
        $this->attachContainerLinks($products);
        $this->attachMergedIntoNames($products);

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
            // Merge target picker — every other active item, grouped
            // client-side by item_type so a product can only merge into
            // another product, a service into a service, etc.
            'merge_candidates' => Product::where('business_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'item_type', 'sku']),
            'filters' => ['type' => $typeFilter ?: 'all', 'search' => $search, 'status' => $statusFilter],
        ]);
    }

    /**
     * Attach the surviving product's name to any row that was merged away,
     * so the Archived tab can show "Merged into X" instead of a bare id.
     */
    private function attachMergedIntoNames(LengthAwarePaginator $products): void
    {
        $targetIds = $products->getCollection()->pluck('merged_into_product_id')->filter()->unique();

        if ($targetIds->isEmpty()) {
            return;
        }

        $names = Product::whereIn('id', $targetIds)->pluck('name', 'id');

        $products->getCollection()->transform(function (Product $product) use ($names) {
            $product->merged_into_product_name = $product->merged_into_product_id
                ? $names->get($product->merged_into_product_id)
                : null;

            return $product;
        });
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

    /**
     * A ready-to-fill CSV: header row + two worked examples (product and
     * service). A single-location business gets a flat "stock_quantity"
     * column, same as always; a multi-location business instead gets one
     * "stock: <location name>" column per active location, so opening
     * balances can be populated per warehouse straight from the sheet — see
     * import()'s matching logic below.
     */
    public function template(): StreamedResponse
    {
        $tenantLocations = Location::where('business_id', $this->tenantId())->where('is_active', true)->orderBy('name')->get(['name']);
        $multiLocation = $tenantLocations->count() > 1;

        $stockColumns = $multiLocation
            ? $tenantLocations->map(fn (Location $location) => "stock: {$location->name}")->all()
            : ['stock_quantity'];
        $columns = array_merge(self::IMPORT_BASE_COLUMNS, $stockColumns, ['low_stock_threshold']);

        $productStockExample = $multiLocation ? array_pad(['30'], count($stockColumns), '0') : ['50'];
        $serviceStockExample = array_fill(0, count($stockColumns), '');

        $rows = [
            $columns,
            array_merge(['Coca Cola 500ml', 'product', '1.50', '0.90', 'COKE500', '6001234567890', 'Beverages', 'piece', 'yes'], $productStockExample, ['10']),
            array_merge(['Phone Screen Repair', 'service', '25.00', '', '', '', 'Repairs', '', 'no'], $serviceStockExample, ['']),
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
     * A live snapshot of the catalogue in the same column shape template()
     * hands out, but populated with every active product/service and its
     * real price, category and per-location balance — edit stock counts in
     * Excel and re-upload through import() to update balances in bulk.
     * Containers are left out (import()'s item_type rule doesn't accept
     * "container", so a round trip couldn't bring them back in anyway).
     */
    public function export(): StreamedResponse
    {
        $tenantId = $this->tenantId();

        $tenantLocations = Location::where('business_id', $tenantId)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $multiLocation = $tenantLocations->count() > 1;

        $stockColumns = $multiLocation
            ? $tenantLocations->map(fn (Location $location) => "stock: {$location->name}")->all()
            : ['stock_quantity'];
        $columns = array_merge(self::IMPORT_BASE_COLUMNS, $stockColumns, ['low_stock_threshold']);

        $products = Product::where('business_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('item_type', ['product', 'service'])
            ->orderBy('name')
            ->get(['id', 'name', 'item_type', 'price', 'cost_price', 'sku', 'barcode', 'category_id', 'unit', 'track_stock', 'stock_quantity', 'low_stock_threshold']);

        $categoryNamesById = Category::where('business_id', $tenantId)->pluck('name', 'id');

        $stockByProduct = $multiLocation
            ? ProductStock::whereIn('product_id', $products->pluck('id'))->get(['product_id', 'location_id', 'quantity'])->groupBy('product_id')
            : collect();

        return response()->streamDownload(function () use ($products, $columns, $tenantLocations, $multiLocation, $stockByProduct, $categoryNamesById) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            foreach ($products as $product) {
                $isService = $product->item_type === 'service';

                $row = [
                    $product->name,
                    $product->item_type,
                    (string) $product->price,
                    (string) $product->cost_price,
                    $product->sku ?? '',
                    $product->barcode ?? '',
                    $product->category_id ? ($categoryNamesById->get($product->category_id) ?? '') : '',
                    $isService ? '' : $product->unit,
                    $isService ? '' : ($product->track_stock ? 'yes' : 'no'),
                ];

                if ($multiLocation) {
                    $rowsByLocation = ($stockByProduct->get($product->id) ?? collect())->keyBy('location_id');
                    foreach ($tenantLocations as $location) {
                        $row[] = $isService ? '' : (string) (float) ($rowsByLocation->get($location->id)?->quantity ?? 0);
                    }
                } else {
                    $row[] = $isService ? '' : (string) $product->stock_quantity;
                }

                $row[] = (string) $product->low_stock_threshold;

                fputcsv($out, $row);
            }

            fclose($out);
        }, 'products-export.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Bulk create/update items from an uploaded CSV built from template() or
     * export(). Matching an existing item is by SKU, then barcode; unmatched
     * rows create a new item. Every write goes through the same sync
     * pipeline as the manual form, so devices pick it up on their next pull.
     *
     * Rows are never deleted or deactivated by omission — a product that
     * exists here but is missing from the file is always left untouched.
     * When full_catalogue is checked (the file is meant to be the entire
     * active catalogue, e.g. from export()), missing products are reported
     * back to the owner instead, so they can archive them deliberately if
     * that was intentional.
     */
    public function import(Request $request, SyncProcessor $processor, LocationService $locations): RedirectResponse
    {
        $tenantId = $this->tenantId();

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'location_id' => ['nullable', 'string', Rule::exists('locations', 'id')->where('business_id', $tenantId)],
            'full_catalogue' => ['sometimes', 'boolean'],
        ]);

        // The whole file lands at one location — the owner picks it in the
        // import dialog (defaulting to the business's default location) since
        // a CSV row has no natural place to carry that per-item. Superseded
        // per-row by $locationColumns below when the file carries its own
        // "stock: <location>" columns instead.
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

        // Per-location stock columns look like "stock: Warehouse 1" — matched
        // case-insensitively against this business's own location names, the
        // way template() generates them for a multi-location business. A
        // file without any of these just keeps using the flat stock_quantity
        // column further down, unchanged.
        $locationColumns = [];
        foreach (Location::where('business_id', $tenantId)->where('is_active', true)->get(['id', 'name']) as $location) {
            $key = 'stock: '.strtolower($location->name);
            if (in_array($key, $header, true)) {
                $locationColumns[$key] = $location;
            }
        }

        $categoryIdsByName = Category::where('business_id', $tenantId)->get(['id', 'name'])
            ->mapWithKeys(fn ($category) => [strtolower(trim($category->name)) => $category->id]);

        $created = 0;
        $updated = 0;
        $errors = [];
        $rowNumber = 1;
        // Every product id this file actually touched (created or matched
        // by SKU/barcode) — used below to work out what full_catalogue's
        // "missing from file" report should list.
        $touchedProductIds = [];

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

            $rules = [
                'name' => ['required', 'string', 'max:255'],
                'item_type' => ['required', 'in:product,service'],
                'price' => ['required', 'numeric', 'min:0'],
                'cost_price' => ['nullable', 'numeric', 'min:0'],
                'sku' => ['nullable', 'string', 'max:100'],
                'barcode' => ['nullable', 'string', 'max:100'],
                'unit' => ['nullable', 'string', 'max:30'],
                'stock_quantity' => ['nullable', 'numeric', 'min:0'],
                'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            ];
            foreach (array_keys($locationColumns) as $key) {
                $rules[$key] = ['nullable', 'numeric', 'min:0'];
            }

            $validator = Validator::make($data, $rules);

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

            // This row's per-location balances, only for columns actually
            // filled in — a blank cell leaves that location untouched rather
            // than zeroing it out.
            $rowLocationStock = [];
            if (! $isService) {
                foreach ($locationColumns as $key => $location) {
                    if (($valid[$key] ?? '') !== '') {
                        $rowLocationStock[$location->id] = (float) $valid[$key];
                    }
                }
            }

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

                foreach ($rowLocationStock as $rowLocationId => $quantity) {
                    $this->applyLocationBalance($processor, $existing, $rowLocationId, $quantity);
                }

                // No per-location "stock: <location>" columns in this file (a
                // single-location business, or a legacy flat file) — reconcile
                // the flat stock_quantity column against the import's chosen
                // location instead, the same place a brand new row's opening
                // stock would land. Without this, editing stock_quantity for
                // an existing item and re-importing would silently do nothing.
                if (! $isService && empty($locationColumns) && ($data['stock_quantity'] ?? '') !== '') {
                    $this->applyLocationBalance($processor, $existing, $locationId, (float) $valid['stock_quantity']);
                }

                $touchedProductIds[] = $existing->id;
                $updated++;
            } else {
                $payload['is_active'] = true;

                if (! empty($rowLocationStock)) {
                    // Same reasoning as store()'s multi-location branch: no
                    // single location owns the opening figure, each entry
                    // below gets its own movement instead.
                    $payload['stock_quantity'] = 0;
                    $payload['location_id'] = null;
                } else {
                    $payload['stock_quantity'] = $isService ? 0 : ($valid['stock_quantity'] ?? 0);
                    $payload['location_id'] = $isService ? null : $locationId;
                }

                $newProductId = (string) Str::uuid();
                $this->applyThroughSyncPipeline($processor, $newProductId, $payload);

                if (! empty($rowLocationStock)) {
                    $newProduct = Product::findOrFail($newProductId);
                    foreach ($rowLocationStock as $rowLocationId => $quantity) {
                        $this->applyLocationBalance($processor, $newProduct, $rowLocationId, $quantity);
                    }
                }

                $touchedProductIds[] = $newProductId;
                $created++;
            }
        }

        fclose($handle);

        $missing = [];
        if ($request->boolean('full_catalogue')) {
            $missing = Product::where('business_id', $tenantId)
                ->where('is_active', true)
                ->whereIn('item_type', ['product', 'service'])
                ->whereNotIn('id', $touchedProductIds)
                ->orderBy('name')
                ->get(['name', 'sku', 'barcode'])
                ->map(function (Product $product) {
                    $identifier = $product->sku ?: $product->barcode;

                    return $identifier ? "{$product->name} ({$identifier})" : $product->name;
                })
                ->all();
        }

        $summary = "Imported {$created} new and updated {$updated} existing item(s). Devices will receive the changes on their next sync.";
        if ($errors) {
            $summary .= ' '.count($errors).' row(s) need attention — see details below.';
        }
        if ($missing) {
            $summary .= ' '.count($missing)." active item(s) weren't in this file — nothing was changed on them, see below.";
        }

        return back()->with('success', $summary)->with('import_errors', $errors ?: null)->with('import_missing', $missing ?: null);
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
        $locationStock = $data['location_stock'];
        unset($data['container_links'], $data['location_stock']);

        // A per-location breakdown was submitted (multi-location businesses
        // only) — the product itself carries no single opening figure; each
        // location gets its own movement below instead, via the same delta
        // primitive setOpeningBalance() uses (baseline is 0 for a brand new
        // product, so the movement equals the entered quantity outright).
        if (! empty($locationStock)) {
            $data['location_id'] = null;
            $data['stock_quantity'] = 0;
        } elseif ($data['track_stock'] && empty($data['location_id'])) {
            // Opening stock has to land somewhere. If the form didn't specify
            // a location (or this is a service, where it's moot), fall back
            // to the business's default rather than leaving it unattributed.
            $data['location_id'] = $locations->ensureDefaultLocation($this->tenantId())->id;
        }

        $uuid = (string) Str::uuid();
        $this->applyThroughSyncPipeline($processor, $uuid, $data);
        $this->applyContainerLinks($processor, $uuid, $containerLinks, existingLinks: collect());

        if (! empty($locationStock)) {
            $product = Product::findOrFail($uuid);
            foreach ($locationStock as $entry) {
                $this->applyLocationBalance($processor, $product, $entry['location_id'], (float) $entry['quantity']);
            }
        }

        return back()->with('success', 'Item created. Devices will receive it on their next sync.');
    }

    public function update(Request $request, string $product, SyncProcessor $processor): RedirectResponse
    {
        $existing = Product::where('business_id', $this->tenantId())->findOrFail($product);
        $data = $this->validatePayload($request, $existing);
        $containerLinks = $data['container_links'];
        $locationStock = $data['location_stock'];
        unset($data['container_links'], $data['location_stock']);

        $this->applyThroughSyncPipeline($processor, $product, $data);
        $this->applyContainerLinks(
            $processor,
            $product,
            $containerLinks,
            existingLinks: ProductContainerLink::where('beverage_product_id', $product)->get()
        );

        foreach ($locationStock as $entry) {
            $this->applyLocationBalance($processor, $existing, $entry['location_id'], (float) $entry['quantity']);
        }

        return back()->with('success', 'Item updated. Devices will receive it on their next sync.');
    }

    public function toggleActive(Request $request, string $product, SyncProcessor $processor): RedirectResponse
    {
        $existing = Product::where('business_id', $this->tenantId())->findOrFail($product);

        $isActive = $this->setProductActive($processor, $existing, ! $existing->is_active);

        return back()->with('success', $isActive ? 'Item restored.' : 'Item archived.');
    }

    /**
     * Merge a duplicate product into a survivor of the same item type: moves
     * every location's current stock across via a paired merge_out/merge_in
     * ledger entry (the same audit-trail idea as a stock transfer), then
     * archives the duplicate and stamps merged_into_product_id so the
     * BackOffice can show why it's gone. Nothing historical is rewritten —
     * every past sale, movement, and receipt still points at its original
     * product id, exactly like any other archived item.
     */
    public function merge(Request $request, string $product, SyncProcessor $processor): RedirectResponse
    {
        $tenantId = $this->tenantId();

        $data = $request->validate([
            'into' => ['required', 'string', Rule::exists('products', 'id')->where('business_id', $tenantId)],
        ]);

        if ($data['into'] === $product) {
            return back()->withErrors(['into' => 'Choose a different item to merge into.']);
        }

        $source = Product::where('business_id', $tenantId)->findOrFail($product);
        $target = Product::where('business_id', $tenantId)->findOrFail($data['into']);

        if ($source->item_type !== $target->item_type) {
            return back()->withErrors(['into' => 'Can only merge items of the same type.']);
        }

        if (! $target->is_active) {
            return back()->withErrors(['into' => 'Cannot merge into an archived item — restore it first or pick another.']);
        }

        DB::transaction(function () use ($processor, $source, $target) {
            if ($source->track_stock) {
                $sourceStock = ProductStock::where('product_id', $source->id)->where('quantity', '>', 0)->get();

                foreach ($sourceStock as $stock) {
                    $quantity = (float) $stock->quantity;
                    $this->postStockMovement($processor, $source, $stock->location_id, 'merge_out', -$quantity, "Merged into {$target->name}", $target->id);
                    $this->postStockMovement($processor, $target, $stock->location_id, 'merge_in', $quantity, "Merged from {$source->name}", $source->id);
                }
            }

            $this->setProductActive($processor, $source, false);

            // Backend-only bookkeeping — not part of the sync payload/products
            // case, since devices only need is_active to stop selling it.
            $source->forceFill(['merged_into_product_id' => $target->id])->save();
        });

        return back()->with('success', "\"{$source->name}\" merged into \"{$target->name}\" and archived. Devices will receive the stock changes on their next sync.");
    }

    /**
     * Archive every currently-active item in one action — reversible
     * per-item via the existing Restore button, and nothing is deleted or
     * touches history. Owner-only given the blast radius (every till loses
     * every product from its sell screen at once).
     */
    public function archiveAll(SyncProcessor $processor): RedirectResponse
    {
        abort_unless(session('backoffice.role') === 'business_owner', 403, 'Only the business owner can archive all items.');

        $tenantId = $this->tenantId();
        $archived = 0;

        Product::where('business_id', $tenantId)->where('is_active', true)
            ->chunkById(100, function ($products) use ($processor, &$archived) {
                foreach ($products as $product) {
                    $this->setProductActive($processor, $product, false);
                    $archived++;
                }
            });

        return back()->with('success', "Archived {$archived} item(s). Each can be restored individually from the Archived tab.");
    }

    /**
     * Full-row product payload with only is_active flipped, run through the
     * same sync pipeline as every other product write — reused by
     * toggleActive(), merge() and archiveAll() so archiving always behaves
     * identically everywhere it happens.
     *
     * Built as plain literals, not $product->only(): Product casts every
     * price/quantity field as decimal:N, which Eloquent renders as a STRING
     * (e.g. "120.0000") to avoid float precision loss. Put through ->only()
     * unchanged, that string lands in the sync payload's JSON and breaks the
     * device's `as num?` cast on pull. Casting back to float/int here keeps
     * the payload numeric on the wire.
     */
    private function setProductActive(SyncProcessor $processor, Product $product, bool $isActive): bool
    {
        $payload = [
            'business_id' => $product->business_id,
            'category_id' => $product->category_id,
            'name' => $product->name,
            'item_type' => $product->item_type,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'price' => (float) $product->price,
            'min_price' => $product->min_price !== null ? (float) $product->min_price : null,
            'discount_percent' => $product->discount_percent !== null ? (float) $product->discount_percent : null,
            'cost_price' => (float) $product->cost_price,
            'deposit_amount' => $product->deposit_amount !== null ? (float) $product->deposit_amount : null,
            'unit' => $product->unit,
            'track_stock' => (bool) $product->track_stock,
            'stock_quantity' => (float) $product->stock_quantity,
            'low_stock_threshold' => (float) $product->low_stock_threshold,
            'image_path' => $product->image_path,
            'expiry_date' => $product->expiry_date?->toIso8601String(),
            'is_active' => $isActive,
        ];

        $processor->process('products', $product->id, 'upsert', $payload);
        $this->publishSyncRecord($product->id, $payload);

        return $isActive;
    }

    /**
     * @param  string  $referenceId  the other product involved, so the ledger
     *                               entry is traceable back to the merge.
     */
    private function postStockMovement(SyncProcessor $processor, Product $product, string $locationId, string $type, float $quantityChange, string $reason, ?string $referenceId = null, ?float $unitCost = null, ?float $runningAvgCost = null): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'business_id' => $product->business_id,
            'location_id' => $locationId,
            'product_id' => $product->id,
            'type' => $type,
            'quantity_change' => $quantityChange,
            'unit_cost' => $unitCost,
            'running_avg_cost' => $runningAvgCost,
            'reason' => $reason,
            'reference_id' => $referenceId,
            'user_id' => $this->userId(),
        ];

        $processor->process('stock_movements', $uuid, 'upsert', $payload);
        $this->publishSyncRecord($uuid, $payload, table: 'stock_movements');
    }

    /**
     * Multi-item goods receipt into one location — the BackOffice counterpart
     * of the till's "Direct receive (no PO)" sheet
     * (`CostService.receiveStock()` in `smart_pos/lib/core/inventory/cost_service.dart`).
     * Recomputes cost_price as the weighted-average cost across existing +
     * received stock, the same formula and 'receive' movement type the till
     * already uses, so a receipt looks identical in the ledger regardless of
     * which side recorded it.
     */
    public function receiveStock(Request $request, SyncProcessor $processor): RedirectResponse
    {
        $tenantId = $this->tenantId();

        $data = $request->validate([
            'location_id' => ['required', 'string', Rule::exists('locations', 'id')->where('business_id', $tenantId)],
            'reason' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $products = Product::where('business_id', $tenantId)
            ->where('track_stock', true)
            ->whereIn('id', collect($data['items'])->pluck('product_id'))
            ->get()
            ->keyBy('id');

        $received = 0;
        foreach ($data['items'] as $item) {
            $product = $products->get($item['product_id']);
            if (! $product) {
                continue;
            }

            $this->applyStockReceipt($processor, $product, $data['location_id'], (float) $item['qty'], (float) $item['unit_cost'], $data['reason'] ?? null);
            $received++;
        }

        if ($received === 0) {
            return back()->withErrors(['items' => 'None of the selected items could be received (check they still track stock).']);
        }

        return back()->with('success', "Received {$received} product(s) into stock. Devices will receive the update on their next sync.");
    }

    /**
     * A ready-to-fill CSV for a bulk receipt: every active, stock-tracked
     * product with its SKU/barcode/name for identification and current
     * cost_price prefilled into unit_cost — leave qty blank (or 0) for
     * anything not in this delivery, fill it in for what arrived, then
     * upload through receiveStockImport(). Mirrors template()/export()'s
     * shape for the product CSV import above.
     */
    public function receiveStockTemplate(): StreamedResponse
    {
        $tenantId = $this->tenantId();

        $products = Product::where('business_id', $tenantId)
            ->where('is_active', true)
            ->where('track_stock', true)
            ->orderBy('name')
            ->get(['sku', 'barcode', 'name', 'cost_price']);

        return response()->streamDownload(function () use ($products) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['sku', 'barcode', 'name', 'qty', 'unit_cost']);
            foreach ($products as $product) {
                fputcsv($out, [$product->sku ?? '', $product->barcode ?? '', $product->name, '', (string) $product->cost_price]);
            }
            fclose($out);
        }, 'receive-stock-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Bulk version of receiveStock() from an uploaded CSV built from
     * receiveStockTemplate() — one location for the whole file (same as a
     * single delivery only ever arrives in one place), matching each row to
     * a product by SKU then barcode, same precedence as the product CSV
     * import. A row with a blank/zero qty is skipped quietly (nothing on
     * this delivery for that product) rather than reported as an error.
     */
    public function receiveStockImport(Request $request, SyncProcessor $processor): RedirectResponse
    {
        $tenantId = $this->tenantId();

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'location_id' => ['required', 'string', Rule::exists('locations', 'id')->where('business_id', $tenantId)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $locationId = $request->string('location_id')->toString();
        $reason = $request->string('reason')->toString() ?: null;

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = $handle ? fgetcsv($handle) : false;

        if ($header === false) {
            return back()->withErrors(['file' => 'The file could not be read or is empty.']);
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        foreach (['qty', 'unit_cost'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($handle);

                return back()->withErrors(['file' => "Missing required column \"{$required}\". Download the template for the expected format."]);
            }
        }

        $received = 0;
        $errors = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($rowNumber - 1 > self::IMPORT_ROW_LIMIT) {
                $errors[] = 'Stopped after '.self::IMPORT_ROW_LIMIT.' rows — split larger deliveries into multiple files.';
                break;
            }

            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $data = [];
            foreach ($header as $index => $key) {
                $data[$key] = trim((string) ($row[$index] ?? ''));
            }

            // Nothing arrived on this line — skip quietly, same tolerance as
            // the "stock: <location>" columns in the product CSV import.
            if (($data['qty'] ?? '') === '' || (float) $data['qty'] <= 0) {
                continue;
            }

            $validator = Validator::make($data, [
                'qty' => ['required', 'numeric', 'min:0.0001'],
                'unit_cost' => ['required', 'numeric', 'min:0'],
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNumber}: ".implode(' ', $validator->errors()->all());

                continue;
            }

            $product = null;
            if (($data['sku'] ?? '') !== '') {
                $product = Product::where('business_id', $tenantId)->where('sku', $data['sku'])->first();
            }
            if (! $product && ($data['barcode'] ?? '') !== '') {
                $product = Product::where('business_id', $tenantId)->where('barcode', $data['barcode'])->first();
            }

            if (! $product) {
                $errors[] = "Row {$rowNumber}: no product matched by SKU or barcode.";

                continue;
            }

            if (! $product->track_stock) {
                $errors[] = "Row {$rowNumber}: \"{$product->name}\" doesn't track stock, skipped.";

                continue;
            }

            $valid = $validator->validated();
            $this->applyStockReceipt($processor, $product, $locationId, (float) $valid['qty'], (float) $valid['unit_cost'], $reason);
            $received++;
        }

        fclose($handle);

        $summary = "Received {$received} product(s) into stock. Devices will receive the update on their next sync.";
        if ($errors) {
            $summary .= ' '.count($errors).' row(s) need attention — see details below.';
        }

        return back()->with('success', $summary)->with('import_errors', $errors ?: null);
    }

    /**
     * Weighted-average-cost recompute + full-row product upsert + ledger
     * write for one receive() line. Split out from receiveStock() so each
     * item gets its own product payload built from fresh field values.
     */
    private function applyStockReceipt(SyncProcessor $processor, Product $product, string $locationId, float $qty, float $unitCost, ?string $reason): void
    {
        $existingQty = max(0.0, (float) $product->stock_quantity);
        $existingCost = (float) $product->cost_price;
        $totalUnits = $existingQty + $qty;
        $newAvgCost = $totalUnits > 0 ? (($existingQty * $existingCost) + ($qty * $unitCost)) / $totalUnits : $unitCost;

        // Full-row product payload, same shape toggleActive() sends — every
        // write through the sync pipeline replaces the whole row, so every
        // untouched field has to be carried through explicitly.
        $payload = [
            'business_id' => $product->business_id,
            'category_id' => $product->category_id,
            'name' => $product->name,
            'item_type' => $product->item_type,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'price' => (float) $product->price,
            'min_price' => $product->min_price !== null ? (float) $product->min_price : null,
            'discount_percent' => $product->discount_percent !== null ? (float) $product->discount_percent : null,
            'cost_price' => $newAvgCost,
            'deposit_amount' => $product->deposit_amount !== null ? (float) $product->deposit_amount : null,
            'unit' => $product->unit,
            'track_stock' => (bool) $product->track_stock,
            // Ledger-owned — the postStockMovement() call below recomputes
            // the true total from stock_movements right after this lands.
            'stock_quantity' => (float) $product->stock_quantity,
            'low_stock_threshold' => (float) $product->low_stock_threshold,
            'image_path' => $product->image_path,
            'expiry_date' => $product->expiry_date?->toIso8601String(),
            'is_active' => (bool) $product->is_active,
        ];
        $this->applyThroughSyncPipeline($processor, $product->id, $payload);

        $this->postStockMovement($processor, $product, $locationId, 'receive', $qty, $reason ?: 'Direct receive via BackOffice', unitCost: $unitCost, runningAvgCost: $newAvgCost);
    }

    /**
     * Set a product's stock at one location to an exact figure, e.g. entering
     * a warehouse's opening balance. Posts the *delta* against the live
     * ledger total (not a raw add) so re-running this with the same number
     * is a no-op and re-editing it twice never stacks — same reconciliation
     * approach as the stock-take approval flow (recordVarianceMovement).
     */
    public function setOpeningBalance(Request $request, string $product, SyncProcessor $processor): RedirectResponse
    {
        $tenantId = $this->tenantId();

        $data = $request->validate([
            'location_id' => ['required', 'string', Rule::exists('locations', 'id')->where('business_id', $tenantId)],
            'quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $existing = Product::where('business_id', $tenantId)->where('track_stock', true)->findOrFail($product);

        $this->applyLocationBalance($processor, $existing, $data['location_id'], (float) $data['quantity']);

        return back()->with('success', 'Opening balance updated. Devices will receive it on their next sync.');
    }

    /**
     * Post a stock_movements entry for the *delta* between a location's live
     * ledger total and the desired figure — the single reconciliation
     * primitive behind setOpeningBalance() and the per-location breakdown on
     * the create/edit form. Re-applying the same number twice is a no-op,
     * same approach as the stock-take approval flow (recordVarianceMovement).
     */
    private function applyLocationBalance(SyncProcessor $processor, Product $product, string $locationId, float $desiredQty): void
    {
        $currentQty = (float) StockMovement::where('product_id', $product->id)
            ->where('location_id', $locationId)
            ->sum('quantity_change');

        $variance = $desiredQty - $currentQty;

        if (abs($variance) < 0.0001) {
            return;
        }

        $uuid = (string) Str::uuid();
        $payload = [
            'business_id' => $product->business_id,
            'location_id' => $locationId,
            'product_id' => $product->id,
            'type' => 'opening_stock',
            'quantity_change' => $variance,
            'reason' => 'Opening balance set via BackOffice',
            'user_id' => $this->userId(),
        ];

        $processor->process('stock_movements', $uuid, 'upsert', $payload);
        $this->publishSyncRecord($uuid, $payload, table: 'stock_movements');
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
            // Per-location opening/current balance breakdown (multi-location
            // businesses only) — see applyLocationBalance(). When present it
            // takes over from the single stock_quantity/location_id pair.
            'location_stock' => ['nullable', 'array'],
            'location_stock.*.location_id' => [
                'required_with:location_stock',
                'string',
                'distinct',
                Rule::exists('locations', 'id')->where('business_id', $this->tenantId()),
            ],
            'location_stock.*.quantity' => ['required_with:location_stock', 'numeric', 'min:0'],
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
            // Stripped off in store()/update() before the product payload is
            // built — not a Product column, handled by applyLocationBalance().
            'location_stock' => $isService ? [] : ($validated['location_stock'] ?? []),
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

    private function userId(): ?string
    {
        return session('backoffice')['user_id'] ?? null;
    }
}
