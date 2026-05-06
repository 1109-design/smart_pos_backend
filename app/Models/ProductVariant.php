<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasUuids;


    protected $fillable = [
        'id', 'product_id', 'name', 'price_modifier', 'stock_quantity', 'barcode', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_modifier' => 'decimal:4',
            'stock_quantity' => 'decimal:4',
            'is_active'      => 'boolean',
        ];
    }
}
