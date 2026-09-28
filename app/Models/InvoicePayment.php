<?php

namespace App\Models;

use App\Events\InvoicePaymentRecorded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePayment extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'invoice_id', 'method', 'amount', 'currency_code',
        'exchange_rate_used', 'base_equivalent', 'recorded_by_user_id', 'paid_at',
        'bank_account_id',
    ];

    protected function casts(): array
    {
        return [
            'exchange_rate_used' => 'decimal:8',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (InvoicePayment $payment): void {
            $invoice = $payment->invoice;
            if ($invoice && $invoice->business_id && $invoice->location_id) {
                InvoicePaymentRecorded::dispatch(
                    $invoice->business_id,
                    $invoice->location_id,
                    $invoice->id,
                    $payment->id,
                );
            }
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
