<?php

namespace App\Models;

use App\Events\ApprovalRequestChanged;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'subject_type', 'subject_id', 'action',
        'requested_by_user_id', 'status', 'approver_user_id', 'approved_at',
        'reason', 'payload_json',
    ];

    protected static function booted(): void
    {
        $dispatch = function (ApprovalRequest $request): void {
            if (! $request->business_id) {
                return;
            }

            ApprovalRequestChanged::dispatch($request->business_id, $request->id);
        };

        static::created($dispatch);

        static::updated(function (ApprovalRequest $request) use ($dispatch): void {
            if (! $request->wasChanged('status')) {
                return;
            }

            $dispatch($request);
        });
    }

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'payload_json' => 'array',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Raised on one device, resolved from another (BackOffice, or a
     * manager's own device) — same multi-device shape as
     * PurchaseOrder/Requisition. Without this guard, a device that raised
     * a request and went offline could resync its own stale 'pending'
     * creation payload after it was already resolved elsewhere, silently
     * regressing the status back to 'pending' — which would then let
     * ApprovalService::resolve() run applyApprovedAction() a second time.
     * That's not idempotent for every action (e.g. change_exchange_rate
     * closes out the previously-current rate and opens a new one on every
     * call), so a second resolution would corrupt the FX rate history.
     */
    public const TERMINAL_STATUSES = ['approved', 'rejected'];

    /**
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        'pending' => ['approved', 'rejected'],
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
