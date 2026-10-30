<?php

namespace App\Services\Payroll;

use App\Models\RolePermission;
use App\Models\User;

/**
 * Checks a till permission (the Dart `Permission` enum name) for a user,
 * against the till's own role_permissions table — the same source
 * SubscriptionController::canOverrideLocationLock() reads. The owner holds
 * every permission; a role with no synced customisation falls back to
 * [$defaultRoles] (permission_provider.dart's built-in defaults).
 */
class TillPermissions
{
    /**
     * @param  list<string>  $defaultRoles
     */
    public function userHas(?User $user, string $businessId, string $permission, array $defaultRoles = []): bool
    {
        if ($user === null || (string) $user->business_id !== (string) $businessId || ! $user->is_active) {
            return false;
        }

        $role = $user->getRoleNames()->first();
        if ($role === 'business_owner') {
            return true;
        }

        $row = RolePermission::where('business_id', $businessId)->where('role', $role)->first();
        if ($row !== null) {
            return in_array($permission, self::decode($row->permissions_json), true);
        }

        return in_array($role, $defaultRoles, true);
    }

    /**
     * Device-synced rows can hold the array double-encoded.
     *
     * @return list<string>
     */
    public static function decode(mixed $raw): array
    {
        $value = $raw;
        for ($i = 0; $i < 2 && is_string($value); $i++) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
