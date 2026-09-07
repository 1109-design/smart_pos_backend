<?php

namespace App\Models;

use App\Events\StockTransferChanged;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'transfer_number', 'from_location_id', 'to_location_id',
        'status', 'notes', 'requested_by_user_id', 'approved_by_user_id',
        'approved_at', 'dispatched_at', 'received_at',
    ];

    protected static function booted(): void
    {
        $dispatch = function (StockTransfer $transfer): void {
            if (! $transfer->business_id || ! $transfer->from_location_id || ! $transfer->to_location_id) {
                return;
            }

            StockTransferChanged::dispatch(
                $transfer->business_id,
                $transfer->from_location_id,
                $transfer->to_location_id,
                $transfer->id,
            );
        };

        static::created($dispatch);

        static::updated(function (StockTransfer $transfer) use ($dispatch): void {
            if (! $transfer->wasChanged('status')) {
                return;
            }

            $dispatch($transfer);
        });
    }

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isInTransit(): bool
    {
        return $this->status === 'in_transit';
    }

    /**
     * Statuses that end a transfer's lifecycle. Once reached, no further
     * transition is accepted — a stale/replayed sync push (e.g. two devices
     * racing to dispatch and cancel the same transfer offline) must not
     * resurrect or override a finished transfer. Mirrors
     * StockTake::TERMINAL_STATUSES/ALLOWED_TRANSITIONS exactly, applied to
     * this model's own lifecycle (see TransferService for the source of
     * truth these transitions are drawn from).
     */
    public const TERMINAL_STATUSES = ['received', 'cancelled'];

    /**
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        'pending' => ['approved', 'in_transit', 'cancelled'],
        'approved' => ['in_transit', 'cancelled'],
        'in_transit' => ['received'],
    ];

    /**
     * Whether moving from $from to $to is a legal transfer transition.
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
}
