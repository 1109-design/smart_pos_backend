<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierReconciliation extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'supplier_id', 'statement_date',
        'statement_closing_balance', 'smart_pos_closing_balance', 'variance',
        'status', 'notes', 'reconciled_by_user_id', 'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'statement_closing_balance' => 'decimal:4',
            'smart_pos_closing_balance' => 'decimal:4',
            'variance' => 'decimal:4',
            'reconciled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierReconciliationItem::class, 'reconciliation_id');
    }
}
