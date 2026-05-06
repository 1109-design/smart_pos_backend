<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasUuids;


    protected $fillable = [
        'id', 'business_id', 'cashier_id', 'opened_at', 'closed_at', 'status',
        'opening_float', 'expected_cash', 'counted_cash', 'variance',
        'total_sales', 'cash_sales', 'card_sales', 'mobile_money_sales',
        'credit_sales', 'total_refunds', 'total_discounts', 'transaction_count',
        'opening_float_json', 'counted_cash_json', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at'          => 'datetime',
            'closed_at'          => 'datetime',
            'opening_float_json' => 'array',
            'counted_cash_json'  => 'array',
        ];
    }
}
