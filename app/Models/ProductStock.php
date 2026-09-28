<?php

namespace App\Models;

use App\Events\StockLevelChanged;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStock extends Model
{
    use HasUuids;

    // Laravel pluralizes to 'product_stocks' by default; actual table is 'product_stock'.
    protected $table = 'product_stock';

    protected $fillable = [
        'id', 'product_id', 'location_id', 'quantity', 'reserved_quantity',
        'low_stock_threshold', 'price_override', 'in_transit_quantity',
    ];

    /**
     * Fires only for writes that actually go through Eloquent's save() path
     * (e.g. SyncProcessor::recomputeLocationStock()'s updateOrCreate()) —
     * NOT for the query-builder-style ::where(...)->increment()/decrement()
     * calls LocationService uses for reserved_quantity/in_transit_quantity
     * (reserveStock, reserveInTransit, etc.), which never fire Eloquent
     * model events at all. Those paths broadcast explicitly via
     * LocationService::publishStock() instead, which every one of them
     * already calls right after mutating the row.
     */
    protected static function booted(): void
    {
        static::updated(function (ProductStock $stock): void {
            if (! $stock->wasChanged(['quantity', 'in_transit_quantity'])) {
                return;
            }

            $businessId = $stock->product?->business_id;
            if ($businessId && $stock->location_id) {
                StockLevelChanged::dispatch($businessId, $stock->location_id, $stock->product_id);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'reserved_quantity' => 'decimal:4',
            'low_stock_threshold' => 'decimal:4',
            'price_override' => 'decimal:4',
            'in_transit_quantity' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Quantity available to sell or transfer out right now. Excludes both
     * order-hold reservations and stock already committed to an outbound
     * in-transit transfer — both represent stock physically unavailable at
     * this location, even though only one of them ever shows up on a shelf
     * count. (At a destination location, in_transit_quantity never touches
     * `quantity` until receipt, so this formula is unaffected by incoming
     * transfers — it only ever reduces availability at the *source*.)
     */
    public function getAvailableQuantityAttribute(): float
    {
        return max(0, (float) $this->quantity - (float) $this->reserved_quantity - (float) $this->in_transit_quantity);
    }

    /**
     * What this location will have on hand once every in-transit transfer
     * addressed to it arrives — for warehouse/branch planning views, not for
     * sell-through decisions (see getAvailableQuantityAttribute).
     */
    public function getExpectedQuantityAttribute(): float
    {
        return (float) $this->quantity + (float) $this->in_transit_quantity;
    }

    /**
     * This location's low-stock threshold if it's been overridden here,
     * else the product's business-wide default.
     */
    public function resolvedLowStockThreshold(): float
    {
        return (float) ($this->low_stock_threshold ?? $this->product?->low_stock_threshold ?? 0);
    }

    /**
     * This location's selling price if it's been overridden here, else the
     * product's business-wide default.
     */
    public function resolvedPrice(): float
    {
        return (float) ($this->price_override ?? $this->product?->price ?? 0);
    }
}
