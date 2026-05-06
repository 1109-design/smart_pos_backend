<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TransactionTax extends Model
{
    use HasUuids;


    public $timestamps = false;

    protected $fillable = [
        'id', 'transaction_id', 'tax_rate_id', 'tax_name',
        'rate_snapshot', 'taxable_amount', 'tax_amount',
    ];

    protected function casts(): array
    {
        return [
            'rate_snapshot'  => 'decimal:4',
            'taxable_amount' => 'decimal:4',
            'tax_amount'     => 'decimal:4',
        ];
    }
}
