<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'from_currency', 'to_currency', 'rate', 'source',
        'set_by_user_id', 'locked', 'valid_from', 'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:8',
            'locked' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }

    /**
     * FX·06 — the ApprovalRequest that raised this rate change. The
     * approval request always gets its own fresh uuid (see
     * requireApproval()/_recordApprovalRequest() in the Flutter app's
     * core/auth/approval_service.dart, and ApprovalService::request() here)
     * — the rateId the till generates client-side becomes this row's own
     * `id` *and* the approval's `subject_id`, never the approval's `id`.
     * So the join is subject_id, not id.
     */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'id', 'subject_id')
            ->where('subject_type', 'ExchangeRate');
    }
}
