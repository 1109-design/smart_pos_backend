<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerDepositLedger extends Model
{
    use HasUuids;

    protected $table = 'container_deposit_ledger';

    protected $fillable = [
        'id', 'business_id', 'location_id', 'customer_id', 'container_product_id',
        'transaction_id', 'quantity', 'deposit_amount_per_unit', 'type',
        'refund_method', 'reason', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'deposit_amount_per_unit' => 'decimal:4',
        ];
    }

    public function containerProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'container_product_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
