<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'sales_return_id', 'original_transaction_item_id', 'product_id',
        'product_name', 'quantity', 'unit_value', 'tax_amount', 'line_value',
        'condition',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_value' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_value' => 'decimal:4',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }
}
