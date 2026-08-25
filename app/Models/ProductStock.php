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
    ];

    protected static function booted(): void
    {
        static::updated(function (ProductStock $stock): void {
            if (! $stock->wasChanged('quantity')) {
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

    /** Quantity available to sell or transfer (excludes reserved stock). */
    public function getAvailableQuantityAttribute(): float
    {
        return max(0, (float) $this->quantity - (float) $this->reserved_quantity);
    }
}
