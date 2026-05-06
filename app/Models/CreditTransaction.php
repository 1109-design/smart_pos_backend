<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    use HasUuids;


    protected $fillable = [
        'id', 'customer_id', 'transaction_id', 'amount', 'type', 'method', 'reference',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }
}
