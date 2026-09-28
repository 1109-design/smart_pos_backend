<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRule extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'rule_set_id', 'level', 'condition_type',
        'condition_value', 'condition_value_max', 'required_role',
        'approval_group_id', 'min_approvers', 'is_sequential', 'sla_hours',
        'escalate_to_role', 'escalate_after_hours', 'require_different_user',
    ];

    protected $casts = [
        'is_sequential' => 'boolean',
        'require_different_user' => 'boolean',
        'condition_value' => 'float',
        'condition_value_max' => 'float',
    ];

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(ApprovalRuleSet::class, 'rule_set_id');
    }
}
