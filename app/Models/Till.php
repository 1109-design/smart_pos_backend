<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Till extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'device_id', 'name', 'register_number', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function locationAudits(): HasMany
    {
        return $this->hasMany(TillLocationAudit::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(TillCashMovement::class);
    }
}
