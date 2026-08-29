<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'customer_id', 'quotation_id',
        'invoice_number', 'type', 'status', 'issue_date', 'due_date', 'payment_terms_days',
        'subtotal', 'discount_total', 'tax_total', 'deposit_required', 'total', 'amount_paid',
        'recurring_schedule_id', 'notes', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function recurringSchedule(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoiceSchedule::class, 'recurring_schedule_id');
    }
}
