<?php

namespace App\Models;

use App\Services\BarcodeRegistry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBarcode extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'product_id', 'barcode',
    ];

    protected static function booted(): void
    {
        // Barcode uniqueness claim — see BarcodeRegistry.
        static::saved(function (ProductBarcode $row): void {
            if ($row->wasRecentlyCreated || $row->wasChanged(['barcode', 'product_id'])) {
                app(BarcodeRegistry::class)->claim(
                    Product::whereKey($row->product_id)->value('business_id'),
                    $row->barcode,
                    'product_barcode',
                    $row->id,
                );
            }
        });
        static::deleted(function (ProductBarcode $row): void {
            app(BarcodeRegistry::class)->release('product_barcode', $row->id);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
