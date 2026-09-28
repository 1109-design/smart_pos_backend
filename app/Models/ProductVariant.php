<?php

namespace App\Models;

use App\Services\BarcodeRegistry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasUuids;


    protected $fillable = [
        'id', 'product_id', 'name', 'price_modifier', 'stock_quantity', 'barcode', 'is_active',
    ];

    protected static function booted(): void
    {
        // Barcode uniqueness claim — see BarcodeRegistry.
        static::saved(function (ProductVariant $variant): void {
            if ($variant->wasRecentlyCreated || $variant->wasChanged(['barcode', 'product_id'])) {
                app(BarcodeRegistry::class)->claim(
                    Product::whereKey($variant->product_id)->value('business_id'),
                    $variant->barcode,
                    'product_variant',
                    $variant->id,
                );
            }
        });
        static::deleted(function (ProductVariant $variant): void {
            app(BarcodeRegistry::class)->release('product_variant', $variant->id);
        });
    }

    protected function casts(): array
    {
        return [
            'price_modifier' => 'decimal:4',
            'stock_quantity' => 'decimal:4',
            'is_active'      => 'boolean',
        ];
    }
}
