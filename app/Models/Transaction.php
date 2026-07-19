<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'user_id', 'customer_id',
        'subtotal', 'tax_total', 'discount_total', 'total',
        'base_currency', 'status', 'sale_number', 'notes',
        'fiscal_status', 'fiscal_receipt_number', 'fiscal_qr_code',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'total' => 'decimal:4',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
