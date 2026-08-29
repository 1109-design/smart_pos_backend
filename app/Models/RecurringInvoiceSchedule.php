<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringInvoiceSchedule extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'customer_id', 'template_json',
        'frequency', 'next_run_date', 'is_active', 'last_generated_invoice_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'template_json' => 'array',
            'next_run_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'recurring_schedule_id');
    }
}
