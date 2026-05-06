<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockTakeItem extends Model
{
    use HasUuids;


    public $timestamps = false;

    protected $fillable = [
        'id', 'stock_take_id', 'product_id', 'product_name',
        'system_qty', 'counted_qty', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'system_qty'  => 'decimal:4',
            'counted_qty' => 'decimal:4',
        ];
    }
}
