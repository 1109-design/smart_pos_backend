<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasUuids;


    protected $fillable = [
        'id', 'business_id', 'code', 'description', 'type', 'value',
        'min_order_amount', 'max_uses', 'uses_count', 'is_active', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'value'            => 'decimal:4',
            'min_order_amount' => 'decimal:4',
            'is_active'        => 'boolean',
            'expires_at'       => 'datetime',
        ];
    }
}
