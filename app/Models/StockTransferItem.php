<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id', 'stock_transfer_id', 'product_id', 'variant_id',
        'product_name', 'qty_requested', 'qty_sent', 'qty_received', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty_requested' => 'decimal:4',
            'qty_sent'      => 'decimal:4',
            'qty_received'  => 'decimal:4',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
