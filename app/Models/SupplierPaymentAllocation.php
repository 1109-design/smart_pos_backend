<?php

namespace App\Models;

use App\Events\SupplierPaymentAllocated;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which SupplierInvoice(s) a SupplierPayment was applied to — see spec
 * §17/§18. Append-only (see SyncProcessor::IMMUTABLE): a correction is a
 * new offsetting allocation, never an edit.
 */
class SupplierPaymentAllocation extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::created(function (SupplierPaymentAllocation $allocation): void {
            if (! $allocation->business_id) {
                return;
            }

            SupplierPaymentAllocated::dispatch($allocation->business_id, $allocation->supplier_payment_id, $allocation->supplier_invoice_id);
        });
    }

    protected $fillable = [
        'id', 'business_id', 'supplier_payment_id', 'supplier_invoice_id',
        'amount', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }
}
