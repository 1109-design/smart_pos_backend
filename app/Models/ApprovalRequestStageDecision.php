<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only per-stage audit trail for an ApprovalRequest. The request row
 * itself only carries current status/level for fast querying; every stage
 * decision along the way (who acted, as themselves or as a delegate, whether
 * the SLA had already been breached, whether they also decided the
 * immediately preceding stage) is recorded here and never mutated.
 */
class ApprovalRequestStageDecision extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'approval_request_id', 'level', 'decision',
        'acted_by_user_id', 'acted_as_delegate_for_user_id', 'reason',
        'sla_breached', 'same_approver_as_prior_stage', 'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'sla_breached' => 'boolean',
            'same_approver_as_prior_stage' => 'boolean',
            'acted_at' => 'datetime',
        ];
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }
}
