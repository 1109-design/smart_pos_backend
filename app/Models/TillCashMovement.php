<?php

namespace App\Models;

use App\Events\TillCashMovementRecorded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TillCashMovement extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'till_id', 'shift_id',
        'type', 'amount', 'reason', 'recorded_by_user_id',
    ];

    // Append-only ledger (see SyncProcessor::IMMUTABLE) — only `created` fires.
    protected static function booted(): void
    {
        static::created(function (TillCashMovement $movement): void {
            if ($movement->business_id && $movement->location_id && $movement->till_id) {
                TillCashMovementRecorded::dispatch($movement->business_id, $movement->location_id, $movement->till_id, $movement->id);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
