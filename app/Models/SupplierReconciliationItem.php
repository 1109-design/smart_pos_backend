<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReconciliationItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'reconciliation_id', 'document_type', 'document_reference',
        'document_date', 'supplier_amount', 'smart_pos_amount', 'difference',
        'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'supplier_amount' => 'decimal:4',
            'smart_pos_amount' => 'decimal:4',
            'difference' => 'decimal:4',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(SupplierReconciliation::class, 'reconciliation_id');
    }
}
