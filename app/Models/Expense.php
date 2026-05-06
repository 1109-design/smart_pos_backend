<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasUuids;


    protected $fillable = [
        'id', 'business_id', 'recorded_by_user_id', 'category', 'description',
        'amount', 'currency_code', 'base_equivalent', 'exchange_rate',
        'payment_method', 'mobile_provider', 'payment_reference',
        'receipt_path', 'notes', 'expense_date', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:4',
            'base_equivalent' => 'decimal:4',
            'exchange_rate'  => 'decimal:8',
            'expense_date'   => 'datetime',
            'deleted_at'     => 'datetime',
        ];
    }
}
