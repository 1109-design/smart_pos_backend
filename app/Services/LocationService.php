<?php

namespace App\Services;

use App\Events\StockLevelChanged;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\SyncRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LocationService
{
    public function __construct(private readonly SyncProcessor $processor) {}

    /**
     * Get or initialise the per-location stock row for a product.
     */
    public function getStock(string $productId, string $locationId): ProductStock
    {
        return ProductStock::firstOrCreate(
            ['product_id' => $productId, 'location_id' => $locationId],
            ['quantity' => 0, 'reserved_quantity' => 0]
        );
    }

    /**
     * Available quantity = quantity − reserved_quantity.
     */
    public function availableStock(string $productId, string $locationId): float
    {
        $row = ProductStock::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        if (! $row) {
            return 0.0;
        }

        return max(0.0, (float) $row->quantity - (float) $row->reserved_quantity);
    }

    /**
     * Total quantity across all locations (for cross-location visibility).
     */
    public function totalStock(string $productId): float
    {
        return (float) ProductStock::where('product_id', $productId)->sum('quantity');
    }

    /**
     * Stock breakdown by location for a product.
     *
     * @return array<int, array{location_id: string, location_name: string, quantity: float, reserved: float, available: float}>
     */
    public function stockByLocation(string $productId): array
    {
        return ProductStock::with('location')
            ->where('product_id', $productId)
            ->get()
            ->map(fn ($row) => [
                'location_id' => $row->location_id,
                'location_name' => $row->location?->name ?? '—',
                'location_type' => $row->location?->type ?? 'shop',
                'quantity' => (float) $row->quantity,
                'reserved' => (float) $row->reserved_quantity,
                'available' => max(0.0, (float) $row->quantity - (float) $row->reserved_quantity),
            ])
            ->all();
    }

    /**
     * Add stock to a location (goods receipt, manual adjustment, transfer arrival).
     * Thread-safe via DB increment.
     */
    public function addStock(string $productId, string $locationId, float $qty): void
    {
        $this->getStock($productId, $locationId);

        ProductStock::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->increment('quantity', $qty);
    }

    /**
     * Deduct stock from a location (sale, disposal, transfer departure after receipt confirmed).
     * Throws if insufficient available stock.
     */
    public function deductStock(string $productId, string $locationId, float $qty): void
    {
        DB::transaction(function () use ($productId, $locationId, $qty) {
            $row = ProductStock::where('product_id', $productId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            $available = $row ? max(0.0, (float) $row->quantity - (float) $row->reserved_quantity) : 0.0;

            if ($available < $qty) {
                throw new \RuntimeException(
                    "Insufficient stock at location. Available: {$available}, requested: {$qty}."
                );
            }

            ProductStock::where('product_id', $productId)
                ->where('location_id', $locationId)
                ->decrement('quantity', $qty);
        });
    }

    /**
     * Reserve stock (locks it ahead of a transfer dispatch or held order).
     * Throws if insufficient available stock.
     */
    public function reserveStock(string $productId, string $locationId, float $qty): void
    {
        DB::transaction(function () use ($productId, $locationId, $qty) {
            $this->getStock($productId, $locationId);

            $row = ProductStock::where('product_id', $productId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            $available = max(0.0, (float) $row->quantity - (float) $row->reserved_quantity);

            if ($available < $qty) {
                throw new \RuntimeException(
                    "Insufficient stock to reserve at location. Available: {$available}, requested: {$qty}."
                );
            }

            ProductStock::where('product_id', $productId)
                ->where('location_id', $locationId)
                ->increment('reserved_quantity', $qty);
        });
    }

    /**
     * Release a previously created reservation (transfer cancelled or reduced).
     */
    public function releaseReservation(string $productId, string $locationId, float $qty): void
    {
        ProductStock::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->decrement('reserved_quantity', min($qty, $this->getStock($productId, $locationId)->reserved_quantity));
    }

    /**
     * Commit stock at the SOURCE of a dispatched transfer — distinct from
     * reserveStock()/reserved_quantity (order-holds), so a transfer and a
     * held order never fight over the same counter. Throws if insufficient
     * available stock, same guard as reserveStock().
     */
    public function reserveInTransit(string $productId, string $locationId, float $qty): void
    {
        DB::transaction(function () use ($productId, $locationId, $qty) {
            $this->getStock($productId, $locationId);

            $row = ProductStock::where('product_id', $productId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            $available = max(0.0, (float) $row->quantity - (float) $row->reserved_quantity - (float) $row->in_transit_quantity);

            if ($available < $qty) {
                throw new \RuntimeException(
                    "Insufficient stock to dispatch from location. Available: {$available}, requested: {$qty}."
                );
            }

            ProductStock::where('product_id', $productId)
                ->where('location_id', $locationId)
                ->increment('in_transit_quantity', $qty);
        });
    }

    /**
     * Release a source location's in-transit commitment (transfer received or
     * cancelled — the stock has either physically left or the dispatch never
     * happened).
     */
    public function releaseInTransit(string $productId, string $locationId, float $qty): void
    {
        ProductStock::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->decrement('in_transit_quantity', min($qty, $this->getStock($productId, $locationId)->in_transit_quantity));
    }

    /**
     * Mark stock as incoming at the DESTINATION of a dispatched transfer — no
     * availability guard (the destination isn't losing anything, only
     * gaining visibility of what's on its way). Cleared the same way as the
     * source side once the transfer is received or cancelled.
     */
    public function markIncoming(string $productId, string $locationId, float $qty): void
    {
        $this->getStock($productId, $locationId);

        ProductStock::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->increment('in_transit_quantity', $qty);
    }

    /** @see markIncoming() — the reverse, once the transfer resolves. */
    public function clearIncoming(string $productId, string $locationId, float $qty): void
    {
        $this->releaseInTransit($productId, $locationId, $qty);
    }

    /**
     * Recompute a product's per-location stock from the movement ledger.
     * Called after syncing stock_movements to keep product_stock consistent.
     *
     * @param  string|null  $locationId  null = recompute all locations for this product
     */
    public function recomputeFromMovements(string $productId, ?string $locationId = null): void
    {
        $query = DB::table('stock_movements')
            ->select('location_id', DB::raw('SUM(quantity_change) as total'))
            ->where('product_id', $productId)
            ->whereNotNull('location_id')
            ->groupBy('location_id');

        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        foreach ($query->get() as $row) {
            ProductStock::updateOrCreate(
                ['product_id' => $productId, 'location_id' => $row->location_id],
                ['quantity' => max(0, (float) $row->total)]
            );
        }
    }

    /**
     * Get all active locations for a business.
     *
     * @return Collection<int, Location>
     */
    public function forBusiness(string $businessId): Collection
    {
        return Location::where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Guarantee a business has at least one location. Businesses created
     * before multi-location support existed (or whose owner never opened the
     * till's location settings) get a single "Main" location seeded here the
     * first time any location-aware BackOffice screen loads, with every
     * existing product's flat stock_quantity snapshotted into it so nothing
     * looks like it lost stock the moment locations become visible.
     */
    public function ensureDefaultLocation(string $businessId): Location
    {
        $existing = Location::where('business_id', $businessId)->orderBy('created_at')->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($businessId) {
            $location = Location::create([
                'id' => (string) Str::uuid(),
                'business_id' => $businessId,
                'name' => 'Main',
                'type' => 'warehouse',
                'can_sell' => true,
                'can_receive' => true,
                'is_active' => true,
            ]);

            SyncRecord::create([
                'business_id' => $businessId,
                'table_name' => 'locations',
                'record_uuid' => $location->id,
                'operation' => 'upsert',
                'payload' => $location->only([
                    'id', 'business_id', 'parent_id', 'name', 'type',
                    'address', 'phone', 'email', 'can_sell', 'can_receive', 'is_active',
                ]),
                'source_updated_at' => now(),
                'synced_at' => now(),
            ]);

            $this->backfillFlatStock($businessId, $location->id);

            return $location;
        });
    }

    /**
     * Snapshot every stock-tracked product's current flat stock_quantity into
     * the new default location's product_stock row. This deliberately writes
     * product_stock directly rather than through a stock_movements ledger
     * entry: a product with prior (location-less) movement history already
     * has that quantity counted in the ledger sum, so replaying it as a new
     * movement here would double it. This is a one-time opening snapshot, not
     * a transaction.
     */
    private function backfillFlatStock(string $businessId, string $locationId): void
    {
        Product::where('business_id', $businessId)
            ->where('track_stock', true)
            ->chunkById(200, function ($products) use ($locationId, $businessId) {
                foreach ($products as $product) {
                    // No 'id' in the update values: this row is brand new (fresh
                    // location), but updateOrCreate must never be handed a key that
                    // would overwrite an existing row's primary key on match.
                    ProductStock::updateOrCreate(
                        ['product_id' => $product->id, 'location_id' => $locationId],
                        ['quantity' => (float) $product->stock_quantity]
                    );
                    $this->publishStock($product->id, $locationId, $businessId);
                }
            });
    }

    /** Broadcast a location's current product_stock row to every device. */
    public function publishStock(string $productId, string $locationId, string $businessId): void
    {
        $stock = $this->getStock($productId, $locationId);

        // SyncProcessor's product_stock case treats a missing key as an
        // explicit null (`$payload['low_stock_threshold'] ?? null`), so this
        // must always echo back the row's *current* override values — a
        // payload that only carries quantity/reserved_quantity would silently
        // wipe out a location's threshold/price override the next time
        // anything (e.g. a transfer dispatch) calls this.
        $payload = [
            'business_id' => $businessId,
            'product_id' => $stock->product_id,
            'location_id' => $stock->location_id,
            'quantity' => (float) $stock->quantity,
            'reserved_quantity' => (float) $stock->reserved_quantity,
            'in_transit_quantity' => (float) $stock->in_transit_quantity,
            'low_stock_threshold' => $stock->low_stock_threshold !== null ? (float) $stock->low_stock_threshold : null,
            'price_override' => $stock->price_override !== null ? (float) $stock->price_override : null,
        ];

        $this->processor->process('product_stock', $stock->id, 'upsert', $payload);

        SyncRecord::create([
            'business_id' => $businessId,
            'table_name' => 'product_stock',
            'record_uuid' => $stock->id,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);

        // Every caller of publishStock() mutates reserved_quantity or
        // in_transit_quantity via a query-builder increment()/decrement(),
        // which never fires ProductStock's own Eloquent 'updated' event —
        // so this is the one place that actually needs to broadcast those
        // changes for Phase 9's real-time push to reach other devices
        // instantly instead of on their next poll.
        StockLevelChanged::dispatch($businessId, $stock->location_id, $stock->product_id);
    }
}
