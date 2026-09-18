<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierCreditNote extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'supplier_id', 'supplier_invoice_id',
        'note_number', 'note_type', 'note_date', 'reason', 'currency_code',
        'exchange_rate', 'subtotal', 'tax_total', 'total', 'status',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'note_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierCreditNoteLine::class, 'supplier_credit_note_id');
    }
}
