<?php

namespace App\Services;

use App\Models\ApprovalDelegation;
use App\Models\ApprovalGroupMember;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\ApprovalRuleSet;
use App\Models\User;
use Illuminate\Support\Collection;

class ApprovalRuleEngine
{
    /**
     * Finds the enabled rule set for a process.
     */
    public function resolveRuleSet(string $businessId, string $process): ?ApprovalRuleSet
    {
        return ApprovalRuleSet::where('business_id', $businessId)
            ->where('process', $process)
            ->where('is_enabled', true)
            ->first();
    }

    /**
     * Checks if condition is met given context (amount, percentage, etc.).
     */
    public function evaluateCondition(ApprovalRule $rule, array $context): bool
    {
        if ($rule->condition_type === 'always' || empty($rule->condition_type)) {
            return true;
        }

        $amount = $context['amount'] ?? 0;
        $percentage = $context['percentage'] ?? 0;
        $quantity = $context['quantity'] ?? 0;

        return match ($rule->condition_type) {
            'amount_gt' => $amount > $rule->condition_value,
            'amount_lte' => $amount <= $rule->condition_value,
            'amount_between' => $amount >= $rule->condition_value && $amount <= $rule->condition_value_max,
            'percentage_gt' => $percentage > $rule->condition_value,
            'quantity_gt' => $quantity > $rule->condition_value,
            default => false,
        };
    }

    /**
     * Finds the applicable rule for a given level in the rule set, given context.
     */
    public function findApplicableRule(ApprovalRuleSet $ruleSet, array $context, int $level): ?ApprovalRule
    {
        $rules = $ruleSet->rules()->where('level', $level)->get();

        foreach ($rules as $rule) {
            if ($this->evaluateCondition($rule, $context)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Checks if a user can approve an approval request at its current stage.
     *
     * Combines three checks:
     *  1. Separation of duties — approver ≠ requester (when rule enforces it)
     *  2. Authority — either membership in the rule's named approver group
     *     (the central-setup model going forward), or the legacy required_role
     *     hierarchy check, for rules that still only carry a role
     *  3. Delegation — approver may be acting as delegate for an eligible
     *     group member or role-holder, scoped to this request's process/level
     */
    public function canApprove(string $businessId, string $approverUserId, ApprovalRequest $request, ?ApprovalRule $rule = null): bool
    {
        // Separation of duties — hard stop
        if (! $this->checkSeparationOfDuties($request, $approverUserId)) {
            return false;
        }

        // No rule at this level — legacy allow (preserves existing behaviour
        // for every process not routed through the rule engine).
        if ($rule === null) {
            return true;
        }

        $process = $rule->ruleSet?->process ?? $request->action;

        if ($rule->approval_group_id !== null) {
            if ($this->isGroupMember($rule->approval_group_id, $approverUserId)) {
                return true;
            }

            foreach ($this->getActiveDelegates($businessId, $approverUserId, $process, $rule->level) as $delegatorId) {
                if ($this->isGroupMember($rule->approval_group_id, $delegatorId)) {
                    return true;
                }
            }

            return false;
        }

        if ($rule->required_role === null) {
            return true;
        }

        $user = User::where('id', $approverUserId)
            ->where('business_id', $businessId)
            ->first();

        if (! $user) {
            return false;
        }

        $approverRoleName = $user->roles->first()?->name ?? '';

        // Direct role match via hierarchy
        if ($this->roleCanApproveFor($approverRoleName, $rule->required_role)) {
            return true;
        }

        // Delegation fallback — check if approver is acting for someone with the right role
        $delegatorIds = $this->getActiveDelegates($businessId, $approverUserId, $process, $rule->level);

        foreach ($delegatorIds as $delegatorId) {
            $delegator = User::where('id', $delegatorId)
                ->where('business_id', $businessId)
                ->first();

            $delegatorRoleName = $delegator ? ($delegator->roles->first()?->name ?? '') : '';
            if ($delegator && $this->roleCanApproveFor($delegatorRoleName, $rule->required_role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $userId is a named member of approval group $groupId.
     */
    public function isGroupMember(string $groupId, string $userId): bool
    {
        return ApprovalGroupMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Assumes canApprove() already passed for $approverUserId against $rule.
     * Returns null if they qualify directly (group member / role holder),
     * or the delegator's user id if they only qualify via an active
     * delegation — used to record ApprovalRequestStageDecision's
     * acted_as_delegate_for_user_id for the audit trail.
     */
    public function resolveDelegationSource(string $businessId, string $approverUserId, ApprovalRule $rule): ?string
    {
        $process = $rule->ruleSet?->process;

        if ($rule->approval_group_id !== null) {
            if ($this->isGroupMember($rule->approval_group_id, $approverUserId)) {
                return null;
            }

            foreach ($this->getActiveDelegates($businessId, $approverUserId, $process, $rule->level) as $delegatorId) {
                if ($this->isGroupMember($rule->approval_group_id, $delegatorId)) {
                    return $delegatorId;
                }
            }

            return null;
        }

        if ($rule->required_role === null) {
            return null;
        }

        $user = User::where('id', $approverUserId)->where('business_id', $businessId)->first();
        $approverRoleName = $user?->roles->first()?->name ?? '';

        if ($this->roleCanApproveFor($approverRoleName, $rule->required_role)) {
            return null;
        }

        foreach ($this->getActiveDelegates($businessId, $approverUserId, $process, $rule->level) as $delegatorId) {
            $delegator = User::where('id', $delegatorId)->where('business_id', $businessId)->first();
            $delegatorRoleName = $delegator ? ($delegator->roles->first()?->name ?? '') : '';

            if ($delegator && $this->roleCanApproveFor($delegatorRoleName, $rule->required_role)) {
                return $delegatorId;
            }
        }

        return null;
    }

    /**
     * Returns false if approver is the same as requester AND the rule enforces
     * separation of duties. Safe-fails (blocks) when rule data is unavailable.
     */
    public function checkSeparationOfDuties(ApprovalRequest $request, string $approverUserId): bool
    {
        // Different person — always ok
        if ($approverUserId !== $request->requested_by_user_id) {
            return true;
        }

        // Same person — check whether the rule explicitly allows it (rare but possible)
        if ($request->rule_set_id) {
            $rule = ApprovalRule::where('rule_set_id', $request->rule_set_id)
                ->where('level', $request->current_level ?? 1)
                ->first();

            if ($rule && ! $rule->require_different_user) {
                return true;
            }
        }

        // Default: block self-approval (safe-fail)
        return false;
    }

    /**
     * Gets the IDs of users who have delegated authority TO this user (active
     * now), optionally scoped to a process/level — a delegation with a null
     * process or level on the row is a wildcard for that dimension.
     */
    public function getActiveDelegates(string $businessId, string $userId, ?string $process = null, ?int $level = null): Collection
    {
        $query = ApprovalDelegation::where('business_id', $businessId)
            ->where('delegate_user_id', $userId)
            ->active();

        if ($process !== null && $level !== null) {
            $query->covering($process, $level);
        }

        return $query->pluck('delegator_user_id');
    }

    /**
     * Returns true if $approverRole sits at or above $requiredRole in the
     * enterprise hierarchy, meaning the approver has sufficient authority.
     *
     * business_owner and branch_manager (and the legacy "owner"/"manager")
     * can approve for any role.
     */
    private function roleCanApproveFor(string $approverRole, string $requiredRole): bool
    {
        if ($approverRole === $requiredRole) {
            return true;
        }

        $level = [
            'business_owner' => 100,
            'owner' => 100,  // legacy alias
            'branch_manager' => 90,
            'manager' => 90,   // legacy alias
            'operations_manager' => 80,
            'finance_manager' => 80,
            'procurement_manager' => 70,
            'warehouse_supervisor' => 70,
            'stock_controller' => 60,
            'sales_supervisor' => 60,
            'accountant' => 60,
            'system_admin' => 60,
            'finance_officer' => 50,
            'procurement_officer' => 50,
            'warehouse_clerk' => 40,
            'hr_officer' => 40,
            'auditor' => 40,
            'senior_cashier' => 30,
            'cashier' => 20,
        ];

        return ($level[$approverRole] ?? 0) >= ($level[$requiredRole] ?? 0);
    }
}
