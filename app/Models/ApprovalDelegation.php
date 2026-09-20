<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApprovalDelegation extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'delegator_user_id', 'delegate_user_id',
        'process', 'level', 'reason', 'starts_at', 'ends_at', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'level' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now);
    }

    /**
     * Scopes to delegations that cover a given process/level — null on the
     * row means that delegation is a wildcard for that dimension.
     */
    public function scopeCovering(Builder $query, string $process, int $level): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('process')->orWhere('process', $process))
            ->where(fn (Builder $q) => $q->whereNull('level')->orWhere('level', $level));
    }
}
