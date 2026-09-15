<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Services\BackOfficeAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Stock Inventory by Location" — a pivot of every trackable product against
 * every location the current session can see, showing on-hand quantity and
 * its cost valuation (quantity * Product.cost_price) side by side per
 * location, plus a cross-location total. Same "quantity * cost price"
 * valuation basis ReportsController's COGS figure is derived from elsewhere
 * (a cost snapshot, not a live selling-price estimate) — this report just
 * doesn't have a per-sale cost snapshot to read, so it uses the product's
 * current cost_price directly.
 */
class StockInventoryByLocationController extends BackOfficeController
{
    public function __invoke(Request $request, BackOfficeAuthorizer $authorizer): Response
    {
        ['locations' => $locations, 'rows' => $rows, 'totals' => $totals, 'filters' => $filters] = $this->build($request, $authorizer);

        $categories = Category::where('business_id', $this->tenantId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('BackOffice/StockInventoryByLocation', [
            'locations' => $locations,
            'rows' => $rows,
            'totals' => $totals,
            'categories' => $categories,
            'currency' => session('backoffice')['currency_code'] ?? 'USD',
            'filters' => $filters,
        ]);
    }

    public function export(Request $request, BackOfficeAuthorizer $authorizer): StreamedResponse
    {
        ['locations' => $locations, 'rows' => $rows] = $this->build($request, $authorizer);

        $filename = 'stock-inventory-by-location-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($locations, $rows) {
            $out = fopen('php://output', 'w');

            $header = ['Product', 'SKU', 'Category'];
            foreach ($locations as $location) {
                $header[] = $location['name'].' - Qty';
                $header[] = $location['name'].' - Value';
            }
            $header[] = 'Total Qty';
            $header[] = 'Total Value';
            fputcsv($out, $header);

            foreach ($rows as $row) {
                $line = [$row['name'], $row['sku'] ?? '', $row['category'] ?? ''];
                foreach ($locations as $location) {
                    $cell = $row['locations'][$location['id']] ?? ['quantity' => 0, 'value' => 0];
                    $line[] = number_format((float) $cell['quantity'], 2, '.', '');
                    $line[] = number_format((float) $cell['value'], 2, '.', '');
                }
                $line[] = number_format((float) $row['total_quantity'], 2, '.', '');
                $line[] = number_format((float) $row['total_value'], 2, '.', '');
                fputcsv($out, $line);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Shared query + pivot-shaping logic behind both the page and the CSV
     * export, so the two can never drift out of sync with each other.
     *
     * @return array{
     *     locations: list<array{id: string, name: string}>,
     *     rows: list<array<string, mixed>>,
     *     totals: array{locations: array<string, array{quantity: float, value: float}>, quantity: float, value: float},
     *     filters: array{search: ?string, category_id: ?string, hide_zero: bool},
     * }
     */
    private function build(Request $request, BackOfficeAuthorizer $authorizer): array
    {
        $tenantId = $this->tenantId();
        $locationIds = $authorizer->currentLocationScope();

        $locations = Location::where('business_id', $tenantId)
            ->where('is_active', true)
            ->when($locationIds, fn ($q) => $q->whereIn('id', $locationIds))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Location $location) => ['id' => $location->id, 'name' => $location->name])
            ->all();

        $locationIdList = array_column($locations, 'id');

        $search = trim((string) $request->string('search'));
        $categoryId = trim((string) $request->string('category_id'));
        $hideZero = $request->boolean('hide_zero');

        $categoryNames = Category::where('business_id', $tenantId)->pluck('name', 'id');

        $products = Product::where('business_id', $tenantId)
            ->where('is_active', true)
            ->where('item_type', 'product')
            ->where('track_stock', true)
            ->when($search !== '', fn ($q) => $q->where(
                fn ($q2) => $q2->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")
            ))
            ->when($categoryId !== '', fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'category_id', 'cost_price']);

        $stockByProduct = ProductStock::whereIn('product_id', $products->pluck('id'))
            ->whereIn('location_id', $locationIdList)
            ->get(['product_id', 'location_id', 'quantity'])
            ->groupBy('product_id');

        $totalsByLocation = array_fill_keys($locationIdList, ['quantity' => 0.0, 'value' => 0.0]);
        $totalQty = 0.0;
        $totalValue = 0.0;

        $rows = $products
            ->map(function (Product $product) use ($stockByProduct, $locationIdList, $categoryNames, &$totalsByLocation, &$totalQty, &$totalValue) {
                $cost = (float) $product->cost_price;
                /** @var Collection $stocks */
                $stocks = $stockByProduct->get($product->id, collect())->keyBy('location_id');

                $byLocation = [];
                $rowQty = 0.0;
                $rowValue = 0.0;
                foreach ($locationIdList as $locationId) {
                    $qty = (float) ($stocks->get($locationId)?->quantity ?? 0);
                    $value = round($qty * $cost, 2);
                    $byLocation[$locationId] = ['quantity' => $qty, 'value' => $value];
                    $rowQty += $qty;
                    $rowValue += $value;
                    $totalsByLocation[$locationId]['quantity'] += $qty;
                    $totalsByLocation[$locationId]['value'] += $value;
                }
                $totalQty += $rowQty;
                $totalValue += $rowValue;

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'category' => $product->category_id ? ($categoryNames->get($product->category_id) ?? null) : null,
                    'cost_price' => $cost,
                    'locations' => $byLocation,
                    'total_quantity' => round($rowQty, 4),
                    'total_value' => round($rowValue, 2),
                ];
            })
            ->when($hideZero, fn (Collection $rows) => $rows->filter(fn (array $row) => $row['total_quantity'] > 0))
            ->values()
            ->all();

        return [
            'locations' => $locations,
            'rows' => $rows,
            'totals' => [
                'locations' => array_map(
                    fn (array $t) => ['quantity' => round($t['quantity'], 4), 'value' => round($t['value'], 2)],
                    $totalsByLocation
                ),
                'quantity' => round($totalQty, 4),
                'value' => round($totalValue, 2),
            ],
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'category_id' => $categoryId !== '' ? $categoryId : null,
                'hide_zero' => $hideZero,
            ],
        ];
    }
}
