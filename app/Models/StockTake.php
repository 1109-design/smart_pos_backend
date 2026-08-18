<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTake extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'title', 'status', 'notes',
        'created_by_user_id', 'approved_by_user_id', 'approved_at', 'review_comment',
    ];

    /**
     * Statuses that end a stock take's lifecycle. Once reached, no further
     * transition is accepted — a stale/replayed sync push must not resurrect
     * a finished stock take.
     */
    public const TERMINAL_STATUSES = ['approved', 'rejected', 'cancelled'];

    /**
     * Allowed status => [allowed next statuses] transitions.
     * "pending_approval" => "in_progress" is the "Reverse to Previous Stage"
     * action — sending a submitted count back for correction instead of
     * outright rejecting it.
     *
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        'draft' => ['in_progress', 'cancelled'],
        'in_progress' => ['pending_approval', 'cancelled'],
        'pending_approval' => ['approved', 'rejected', 'in_progress', 'cancelled'],
    ];

    /**
     * Whether moving from $from to $to is a legal stock take transition.
     * Creating a new record (no prior status) or leaving the status
     * unchanged is always allowed.
     */
    public static function isValidTransition(?string $from, string $to): bool
    {
        if ($from === null || $from === $to) {
            return true;
        }

        if (in_array($from, self::TERMINAL_STATUSES, true)) {
            return false;
        }

        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTakeItem::class);
    }
}
