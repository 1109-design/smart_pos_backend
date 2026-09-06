<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id', 'requisition_id', 'product_id', 'product_name',
        'quantity_requested', 'quantity_issued', 'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:4',
            'quantity_issued' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function costTotal(): float
    {
        return (float) $this->quantity_issued * (float) ($this->unit_cost ?? 0);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
