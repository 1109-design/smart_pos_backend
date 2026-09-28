<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceTier extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'product_id', 'min_qty', 'unit_price',
    ];

    protected function casts(): array
    {
        return [
            'min_qty' => 'decimal:4',
            'unit_price' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
