<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptInspection extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'purchase_order_id', 'purchase_order_item_id',
        'product_id', 'product_name', 'delivered_qty', 'accepted_qty',
        'rejected_qty', 'result', 'notes', 'inspected_by_user_id',
        'inspected_by_name', 'inspected_at',
    ];

    protected function casts(): array
    {
        return [
            'delivered_qty' => 'decimal:4',
            'accepted_qty' => 'decimal:4',
            'rejected_qty' => 'decimal:4',
            'inspected_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
