<?php

namespace App\Services;

use App\Models\Location;
use App\Models\ProductStock;
use App\Models\ProductVariantStock;
use Illuminate\Support\Facades\DB;

class LocationService
{
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
                'location_id'   => $row->location_id,
                'location_name' => $row->location?->name ?? '—',
                'location_type' => $row->location?->type ?? 'shop',
                'quantity'      => (float) $row->quantity,
                'reserved'      => (float) $row->reserved_quantity,
                'available'     => max(0.0, (float) $row->quantity - (float) $row->reserved_quantity),
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
            $row = $this->getStock($productId, $locationId);
            $row->refresh()->lockForUpdate();

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
     * Recompute a product's per-location stock from the movement ledger.
     * Called after syncing stock_movements to keep product_stock consistent.
     *
     * @param  string  $productId
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
     * @return \Illuminate\Database\Eloquent\Collection<int, Location>
     */
    public function forBusiness(string $businessId): \Illuminate\Database\Eloquent\Collection
    {
        return Location::where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
