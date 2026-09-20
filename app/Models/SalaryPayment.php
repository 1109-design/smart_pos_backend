<?php

namespace App\Models;

use App\Events\SalaryPaymentRecorded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryPayment extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'employee_id', 'period',
        'amount', 'currency_code', 'base_equivalent', 'exchange_rate',
        'payment_method', 'reference', 'notes', 'paid_by_user_id', 'paid_at',
        'bank_account_id',
    ];

    protected static function booted(): void
    {
        static::created(function (SalaryPayment $payment): void {
            if (! $payment->business_id) {
                return;
            }

            SalaryPaymentRecorded::dispatch($payment->business_id, $payment->employee_id, $payment->id);
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'base_equivalent' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'paid_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
