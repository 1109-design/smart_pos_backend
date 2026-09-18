<?php

namespace App\Models;

use App\Events\SupplierPaymentRecorded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'supplier_id', 'amount', 'currency_code',
        'payment_date', 'method', 'reference', 'recorded_by_user_id',
        'bank_account_id',
    ];

    protected static function booted(): void
    {
        static::created(function (SupplierPayment $payment): void {
            if (! $payment->business_id) {
                return;
            }

            SupplierPaymentRecorded::dispatch($payment->business_id, $payment->supplier_id, $payment->id);
        });
    }

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
