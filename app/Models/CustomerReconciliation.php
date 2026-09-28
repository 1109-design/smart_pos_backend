<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerReconciliation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reconciliation_date' => 'date',
            'statement_cutoff_date' => 'date',
            'customer_statement_balance' => 'decimal:4',
            'ledger_balance' => 'decimal:4',
            'variance' => 'decimal:4',
            'reconciled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerReconciliationItem::class);
    }
}
