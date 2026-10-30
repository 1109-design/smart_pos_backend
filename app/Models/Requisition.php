<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Requisition extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'requisition_number', 'location_id', 'purpose',
        'project_id', 'notes', 'status', 'requested_by_user_id',
        'approved_by_user_id', 'approved_at', 'issued_by_user_id', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequisitionItem::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * A requisition is raised on one device and approved/issued from
     * another (manager PC, warehouse till) — same multi-device shape as
     * PurchaseOrder/StockTransfer. See RequisitionDetailScreen's doc
     * comment for the exact three actions (approve/reject/issue) plus
     * cancel, available from 'pending' and/or 'approved'.
     */
    public const TERMINAL_STATUSES = ['issued', 'rejected', 'cancelled'];

    /**
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        // pending -> issued: a device that approves and then issues before
        // its next push only sends the final 'issued' row (its outbox keeps
        // the latest state per record), so the server never sees
        // 'approved'. Rejecting that left the requisition stuck at pending
        // and its stock never deducted anywhere else.
        'pending' => ['approved', 'rejected', 'cancelled', 'issued'],
        'approved' => ['issued', 'cancelled'],
    ];

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
