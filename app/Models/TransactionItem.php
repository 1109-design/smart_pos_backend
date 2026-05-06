<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TransactionItem extends Model
{
    use HasUuids;


    public $timestamps = false;

    protected $fillable = [
        'id', 'transaction_id', 'product_id', 'variant_id', 'product_name',
        'quantity', 'unit_price', 'discount', 'tax_amount', 'line_total', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity'   => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount'   => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }
}
