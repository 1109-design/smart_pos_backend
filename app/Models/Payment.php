<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasUuids;


    public $timestamps = false;

    protected $fillable = [
        'id', 'transaction_id', 'method', 'amount', 'currency_code',
        'exchange_rate_used', 'base_equivalent', 'change_given', 'reference',
    ];

    protected function casts(): array
    {
        return [
            'amount'             => 'decimal:4',
            'exchange_rate_used' => 'decimal:8',
            'base_equivalent'    => 'decimal:4',
            'change_given'       => 'decimal:4',
        ];
    }
}
