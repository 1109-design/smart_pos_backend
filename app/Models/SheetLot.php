<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SheetLot extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'product_id', 'location_id',
        'original_width', 'original_height', 'area', 'status',
        'received_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'original_width' => 'decimal:4',
            'original_height' => 'decimal:4',
            'area' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function cuts(): HasMany
    {
        return $this->hasMany(SheetCut::class);
    }

    public function originalArea(): float
    {
        return (float) ($this->original_width ?? 0) * (float) ($this->original_height ?? 0);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }
}
