<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockTransfer;
use App\Services\LocationService;
use App\Services\TransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A monitoring layer on top of the existing stock-transfer engine
 * (StockTransfer/TransferService) — no new stock logic of its own. Answers
 * three questions an owner managing several branches actually asks: where is
 * stock running low, what transfers need my attention right now, and what
 * should I move from the warehouse to fix it.
 */
class StoremanController extends Controller
{
    /**
     * A location counts as "surplus" for a product once its stock clears
     * this multiple of the product's own low-stock threshold — comfortably
     * above the point the location itself would start worrying, so a
     * suggested transfer doesn't just relocate the shortage.
     */
    private const SURPLUS_MULTIPLIER = 2.0;

    /** An in-transit transfer this old probably needs a nudge, not just a wait. */
    private const STUCK_TRANSIT_DAYS = 3;

    public function index(LocationService $locationService): Response
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();
        $locationService->ensureDefaultLocation($tenantId);

        $locations = Location::where('business_id', $tenantId)->where('is_active', true)->get(['id', 'name', 'type', 'parent_id']);

        [$stockHealth, $suggestions] = $this->stockHealthAndSuggestions($tenantId, $locations);

        return Inertia::render('BackOffice/Storeman', [
            'stock_health' => $stockHealth,
            'suggestions' => $suggestions,
            'pending_actions' => $this->pendingActions($tenantId),
        ]);
    }

    /**
     * Turn a suggestion card into a real transfer request — pre-filling the
     * exact same TransferService::request() every "New Transfer" on the
     * Transfers page goes through, so there is no second stock-moving code
     * path to keep in sync with the first.
     */
    public function createSuggestedTransfer(Request $request, TransferService $transferService): RedirectResponse
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();

        $data = $request->validate([
            'product_id' => ['required', 'string', 'exists:products,id'],
            'from_location_id' => ['required', 'string', 'different:to_location_id', 'exists:locations,id'],
            'to_location_id' => ['required', 'string', 'exists:locations,id'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $locationCount = Location::where('business_id', $tenantId)
            ->whereIn('id', [$data['from_location_id'], $data['to_location_id']])
            ->count();
        abort_if($locationCount !== 2, 404);

        $product = Product::where('business_id', $tenantId)->findOrFail($data['product_id']);

        try {
            $transferService->request([
                'business_id' => $tenantId,
                'from_location_id' => $data['from_location_id'],
                'to_location_id' => $data['to_location_id'],
                'notes' => 'Requested from Storeman suggestions.',
                'requested_by_user_id' => $this->userId(),
                'items' => [[
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'qty_requested' => $data['qty'],
                ]],
            ]);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return redirect()->route('office.storeman.index')->with('success', "Transfer requested for {$product->name}.");
    }

    /**
     * One pass over every tracked product's stock, business-wide: builds the
     * per-location low/surplus counts and the warehouse→shop suggestions in
     * the same loop rather than two separate N-location queries.
     *
     * @param  Collection<int, Location>  $locations
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function stockHealthAndSuggestions(string $tenantId, $locations): array
    {
        $locationsById = $locations->keyBy('id');

        $rows = ProductStock::query()
            ->join('products', 'products.id', '=', 'product_stock.product_id')
            ->whereIn('product_stock.location_id', $locations->pluck('id'))
            ->where('products.business_id', $tenantId)
            ->where('products.track_stock', true)
            ->where('products.is_active', true)
            ->get([
                'product_stock.location_id',
                'product_stock.product_id',
                'product_stock.quantity',
                'products.name as product_name',
                'products.low_stock_threshold',
            ]);

        $byLocation = $rows->groupBy('location_id');

        $health = $locations->map(function (Location $location) use ($byLocation) {
            $rows = $byLocation->get($location->id, collect());
            $low = $rows->filter(fn ($r) => (float) $r->quantity <= (float) $r->low_stock_threshold)->count();
            $surplus = $rows->filter(fn ($r) => (float) $r->quantity > (float) $r->low_stock_threshold * self::SURPLUS_MULTIPLIER)->count();

            return [
                'id' => $location->id,
                'name' => $location->name,
                'type' => $location->type,
                'low_stock_count' => $low,
                'surplus_count' => $surplus,
                'sku_count' => $rows->count(),
            ];
        })->values()->all();

        // Warehouse→shop suggestions: for every shop's low-stock line, check
        // whether its own supplying warehouse (Location.parent_id) is sitting
        // on surplus of that same product.
        $suggestions = [];
        foreach ($locations->where('type', 'shop')->whereNotNull('parent_id') as $shop) {
            $warehouse = $locationsById->get($shop->parent_id);
            if (! $warehouse) {
                continue;
            }

            $shopRows = $byLocation->get($shop->id, collect())->keyBy('product_id');
            $warehouseRows = $byLocation->get($warehouse->id, collect())->keyBy('product_id');

            foreach ($shopRows as $productId => $shopRow) {
                $threshold = (float) $shopRow->low_stock_threshold;
                $shopQty = (float) $shopRow->quantity;
                if ($shopQty > $threshold) {
                    continue;
                }

                $warehouseRow = $warehouseRows->get($productId);
                if (! $warehouseRow) {
                    continue;
                }

                $warehouseSurplus = (float) $warehouseRow->quantity - $threshold;
                if ($warehouseSurplus <= 0) {
                    continue;
                }

                $shortfall = $threshold - $shopQty;
                $suggestedQty = min($shortfall > 0 ? $shortfall : 1, $warehouseSurplus);

                $suggestions[] = [
                    'product_id' => $productId,
                    'product_name' => $shopRow->product_name,
                    'from_location_id' => $warehouse->id,
                    'from_location_name' => $warehouse->name,
                    'to_location_id' => $shop->id,
                    'to_location_name' => $shop->name,
                    'shop_quantity' => $shopQty,
                    'warehouse_quantity' => (float) $warehouseRow->quantity,
                    'suggested_qty' => round($suggestedQty, 2),
                ];
            }
        }

        return [$health, $suggestions];
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingActions(string $tenantId): array
    {
        $pending = StockTransfer::with(['fromLocation:id,name', 'toLocation:id,name'])
            ->where('business_id', $tenantId)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get(['id', 'transfer_number', 'from_location_id', 'to_location_id', 'created_at']);

        $inTransit = StockTransfer::with(['fromLocation:id,name', 'toLocation:id,name'])
            ->where('business_id', $tenantId)
            ->where('status', 'in_transit')
            ->orderBy('dispatched_at')
            ->get(['id', 'transfer_number', 'from_location_id', 'to_location_id', 'dispatched_at']);

        $stuckCutoff = now()->subDays(self::STUCK_TRANSIT_DAYS);

        return [
            'awaiting_approval' => $pending->map(fn (StockTransfer $t) => [
                'id' => $t->id,
                'transfer_number' => $t->transfer_number,
                'from' => $t->fromLocation?->name,
                'to' => $t->toLocation?->name,
                'created_at' => $t->created_at,
            ])->values(),
            'awaiting_receipt' => $inTransit->map(fn (StockTransfer $t) => [
                'id' => $t->id,
                'transfer_number' => $t->transfer_number,
                'from' => $t->fromLocation?->name,
                'to' => $t->toLocation?->name,
                'dispatched_at' => $t->dispatched_at,
                'stuck' => $t->dispatched_at !== null && $t->dispatched_at->lt($stuckCutoff),
            ])->values(),
        ];
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
