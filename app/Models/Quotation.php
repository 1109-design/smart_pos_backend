<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'customer_id', 'quote_number',
        'status', 'valid_until', 'subtotal', 'discount_total', 'tax_total', 'total',
        'notes', 'parent_quotation_id', 'created_by_user_id',
        'sent_at', 'accepted_at', 'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'parent_quotation_id');
    }
}
