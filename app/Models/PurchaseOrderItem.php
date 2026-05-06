<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasUuids;


    public $timestamps = false;

    protected $fillable = [
        'id', 'purchase_order_id', 'product_id', 'product_name',
        'ordered_qty', 'received_qty', 'unit_cost', 'received_unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'ordered_qty'        => 'decimal:4',
            'received_qty'       => 'decimal:4',
            'unit_cost'          => 'decimal:4',
            'received_unit_cost' => 'decimal:4',
        ];
    }
}
