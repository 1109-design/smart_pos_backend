<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariantStock extends Model
{
    use HasUuids;

    // Laravel pluralizes to 'product_variant_stocks' by default; actual table is 'product_variant_stock'.
    protected $table = 'product_variant_stock';

    protected $fillable = [
        'id', 'variant_id', 'location_id', 'quantity', 'reserved_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity'          => 'decimal:4',
            'reserved_quantity' => 'decimal:4',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
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
