<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReconciliationItem extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'item_date' => 'date',
            'ledger_amount' => 'decimal:4',
            'statement_amount' => 'decimal:4',
            'difference' => 'decimal:4',
            'is_resolved' => 'boolean',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(CustomerReconciliation::class, 'customer_reconciliation_id');
    }
}
