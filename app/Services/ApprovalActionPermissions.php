<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Payroll\TillPermissions;

/**
 * Actions whose decider must also hold a specific till permission, on top
 * of separation of duties and any stage rule — the same
 * `requiredPermission` the till's on-the-spot PIN check uses
 * (approval_service.dart's requireApproval) and its own inbox gate
 * (approval_resolution.dart's _actionRequiredPermission), so approving
 * from BackOffice or a synced decision is never looser than at the till.
 */
class ApprovalActionPermissions
{
    /**
     * action => [till permission, roles holding it by default].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const REQUIRED = [
        'refund_transaction' => [
            'approveSalesReturn',
            ['manager', 'branch_manager', 'operations_manager'],
        ],
    ];

    public function __construct(private readonly TillPermissions $permissions) {}

    /**
     * Why [$decider] can't decide [$request], or null if they can (or the
     * action needs no extra permission).
     */
    public function missing(ApprovalRequest $request, ?User $decider): ?string
    {
        $required = self::REQUIRED[$request->action] ?? null;
        if ($required === null) {
            return null;
        }

        [$permission, $defaultRoles] = $required;

        return $this->permissions->userHas($decider, (string) $request->business_id, $permission, $defaultRoles)
            ? null
            : "Deciding this needs the {$permission} permission.";
    }
}
