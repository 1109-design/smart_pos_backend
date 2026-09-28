<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrvItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'grv_id', 'stock_movement_id', 'product_id', 'product_name',
        'quantity_received', 'quantity_accepted', 'quantity_rejected',
        'rejection_reason', 'unit_cost', 'landed_unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:4',
            'quantity_accepted' => 'decimal:4',
            'quantity_rejected' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'landed_unit_cost' => 'decimal:4',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedVoucher::class, 'grv_id');
    }
}
