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
}
