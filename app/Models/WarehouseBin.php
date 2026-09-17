<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GLS·03 — a structured Zone/Rack/Bay/Position address within one warehouse
 * Location. Deliberately its own table rather than deepening Locations'
 * flat shop|warehouse model — see the Flutter side's identical note on
 * WarehouseBins in tables.dart.
 */
class WarehouseBin extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'zone', 'rack', 'bay', 'position',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function sheetLots(): HasMany
    {
        return $this->hasMany(SheetLot::class);
    }

    public function label(): string
    {
        return collect([$this->zone, $this->rack, $this->bay, $this->position])
            ->filter()
            ->implode('/');
    }
}
