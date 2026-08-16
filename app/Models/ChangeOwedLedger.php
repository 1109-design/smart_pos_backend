<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeOwedLedger extends Model
{
    use HasUuids;

    protected $table = 'change_owed_ledger';

    protected $fillable = [
        'id', 'business_id', 'location_id', 'customer_id', 'transaction_id',
        'amount', 'currency_code', 'type', 'reason', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
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
