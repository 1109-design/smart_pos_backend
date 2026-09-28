<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOversell extends Model
{
    protected $fillable = [
        'business_id',
        'product_id',
        'location_id',
        'computed_quantity',
        'shortfall',
        'detected_at',
        'resolved_at',
        'resolved_by_user_id',
        'resolution',
        'notes',
    ];

    protected $casts = [
        'computed_quantity' => 'float',
        'shortfall' => 'float',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
