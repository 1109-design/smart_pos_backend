<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoReceiptVariance extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'purchase_order_id', 'purchase_order_item_id',
        'product_id', 'product_name', 'ordered_qty', 'received_qty',
        'rejected_qty', 'variance_qty', 'status', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'ordered_qty' => 'decimal:4',
            'received_qty' => 'decimal:4',
            'rejected_qty' => 'decimal:4',
            'variance_qty' => 'decimal:4',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
