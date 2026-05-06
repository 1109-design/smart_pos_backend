<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'parent_id', 'name', 'type',
        'address', 'phone', 'email', 'can_sell', 'can_receive', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'can_sell'    => 'boolean',
            'can_receive' => 'boolean',
            'is_active'   => 'boolean',
        ];
    }

    /** The warehouse that supplies this location (null for top-level warehouses). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    /** Child locations (shops) supplied by this warehouse. */
    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id');
    }

    public function productStock(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function outboundTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'from_location_id');
    }

    public function inboundTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'to_location_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}
