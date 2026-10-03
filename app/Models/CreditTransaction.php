<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'customer_id', 'transaction_id', 'amount', 'type', 'method', 'reference', 'receipt_number',
        'bank_account_id', 'created_by_user_id', 'currency_code', 'exchange_rate', 'base_amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'base_amount' => 'decimal:4',
        ];
    }
}
