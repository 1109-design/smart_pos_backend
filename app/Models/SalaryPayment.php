<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return [
            'amount'          => 'decimal:4',
            'base_equivalent' => 'decimal:4',
            'exchange_rate'   => 'decimal:8',
            'paid_at'         => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
