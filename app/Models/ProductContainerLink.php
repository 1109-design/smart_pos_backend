<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductContainerLink extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id', 'beverage_product_id', 'container_product_id', 'quantity_per_unit',
    ];

    protected function casts(): array
    {
        return [
            'quantity_per_unit' => 'decimal:4',
        ];
    }

    public function beverageProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'beverage_product_id');
    }

    public function containerProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'container_product_id');
    }
}
