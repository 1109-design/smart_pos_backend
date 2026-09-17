<?php

namespace App\Services;

use App\Models\ApprovalDelegation;
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
     * Checks if a user can approve an approval request.
     *
     * Combines three checks:
     *  1. Separation of duties — approver ≠ requester (when rule enforces it)
     *  2. Role hierarchy — approver's role is at or above the required_role level
     *  3. Delegation — approver may be acting as delegate for an eligible user
     */
    public function canApprove(string $businessId, string $approverUserId, ApprovalRequest $request, ?string $requiredRole = null): bool
    {
        // Separation of duties — hard stop
        if (! $this->checkSeparationOfDuties($request, $approverUserId)) {
            return false;
        }

        // If no required role, fall back to legacy allow (preserves existing behaviour)
        if ($requiredRole === null) {
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
        if ($this->roleCanApproveFor($approverRoleName, $requiredRole)) {
            return true;
        }

        // Delegation fallback — check if approver is acting for someone with the right role
        $delegatorIds = $this->getActiveDelegates($businessId, $approverUserId);

        foreach ($delegatorIds as $delegatorId) {
            $delegator = User::where('id', $delegatorId)
                ->where('business_id', $businessId)
                ->first();

            $delegatorRoleName = $delegator ? ($delegator->roles->first()?->name ?? '') : '';
            if ($delegator && $this->roleCanApproveFor($delegatorRoleName, $requiredRole)) {
                return true;
            }
        }

        return false;
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
     * Gets the IDs of users who have delegated authority TO this user (active now).
     */
    public function getActiveDelegates(string $businessId, string $userId): Collection
    {
        return ApprovalDelegation::where('business_id', $businessId)
            ->where('delegate_user_id', $userId)
            ->active()
            ->pluck('delegator_user_id');
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
