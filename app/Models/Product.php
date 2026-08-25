<?php

namespace App\Models;

use App\Events\ProductPriceChanged;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'category_id', 'name', 'item_type', 'sku', 'barcode',
        'price', 'min_price', 'discount_percent', 'cost_price', 'deposit_amount', 'unit',
        'track_stock', 'stock_quantity', 'low_stock_threshold',
        'image_path', 'expiry_date', 'is_active',
    ];

    protected static function booted(): void
    {
        static::updated(function (Product $product): void {
            if (! $product->wasChanged(['price', 'min_price', 'discount_percent', 'is_active']) || ! $product->business_id) {
                return;
            }

            ProductPriceChanged::dispatch($product->business_id, $product->id);
        });
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'min_price' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'cost_price' => 'decimal:4',
            'deposit_amount' => 'decimal:4',
            'stock_quantity' => 'decimal:4',
            'low_stock_threshold' => 'decimal:4',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
            'expiry_date' => 'datetime',
        ];
    }
}
