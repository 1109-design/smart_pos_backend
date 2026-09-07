<?php

namespace App\Models;

use App\Events\PurchaseOrderChanged;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'receiving_location_id', 'supplier_id', 'supplier_name',
        'po_number', 'status', 'total_ordered', 'total_received', 'notes',
        'expected_date', 'additional_costs_json', 'created_by_user_id',
    ];

    protected static function booted(): void
    {
        static::created(function (PurchaseOrder $po): void {
            if (! $po->business_id) {
                return;
            }

            PurchaseOrderChanged::dispatch($po->business_id, $po->receiving_location_id, $po->id);
        });

        static::updated(function (PurchaseOrder $po): void {
            if (! $po->wasChanged(['status', 'total_received']) || ! $po->business_id) {
                return;
            }

            PurchaseOrderChanged::dispatch($po->business_id, $po->receiving_location_id, $po->id);
        });
    }

    protected function casts(): array
    {
        return [
            'total_ordered' => 'decimal:4',
            'total_received' => 'decimal:4',
            'expected_date' => 'datetime',
            'additional_costs_json' => 'array',
        ];
    }

    public function receivingLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'receiving_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
