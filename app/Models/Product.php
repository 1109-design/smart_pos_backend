<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasUuids;


    protected $fillable = [
        'id', 'business_id', 'category_id', 'name', 'sku', 'barcode',
        'price', 'min_price', 'discount_percent', 'cost_price', 'unit',
        'track_stock', 'stock_quantity', 'low_stock_threshold',
        'image_path', 'expiry_date', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price'               => 'decimal:4',
            'min_price'           => 'decimal:4',
            'discount_percent'    => 'decimal:2',
            'cost_price'          => 'decimal:4',
            'stock_quantity'      => 'decimal:4',
            'low_stock_threshold' => 'decimal:4',
            'track_stock'         => 'boolean',
            'is_active'           => 'boolean',
            'expiry_date'         => 'datetime',
        ];
    }
}
