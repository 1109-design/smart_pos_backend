<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasUuids;


    protected $fillable = [
        'id', 'business_id', 'location_id', 'to_location_id', 'product_id', 'type',
        'quantity_change', 'unit_cost', 'running_avg_cost', 'reason', 'reference_id',
        'attachment_path', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change'  => 'decimal:4',
            'unit_cost'        => 'decimal:4',
            'running_avg_cost' => 'decimal:4',
        ];
    }
}
