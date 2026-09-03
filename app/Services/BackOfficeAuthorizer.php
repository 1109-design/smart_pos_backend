<?php

namespace App\Services;

use App\Models\BackOfficeRolePermission;
use App\Models\User;
use App\Support\BackOfficePermission;

class BackOfficeAuthorizer
{
    /**
     * A non-empty, guaranteed-impossible-to-match location id. Used as the
     * "fail closed" scope for a session whose user can't be resolved at all
     * (stale/invalid session), as opposed to locationScope()'s null return
     * for a genuinely-resolved-but-never-scoped user (which correctly means
     * unrestricted). Deliberately non-empty: every call site filters with
     * `->when($scope, fn ($q) => $q->whereIn($column, $scope))`, and PHP
     * treats an empty array as falsy — an empty-array "no access" signal
     * would silently skip the filter and show everything, the opposite of
     * what's intended. A nil UUID can never match a real Str::uuid() row.
     */
    private const NO_ACCESS = ['00000000-0000-0000-0000-000000000000'];

    /**
     * The single place a controller should resolve "what can this session
     * see" — replaces the repeated
     * `locationScope(User::find($session['user_id'] ?? null))` that used to
     * be duplicated across every location-scoped controller. Fails closed
     * (NO_ACCESS) when the session's user can't be resolved at all, rather
     * than locationScope()'s historical null-user behavior of granting
     * unrestricted access.
     *
     * @return list<string>|null
     */
    public function currentLocationScope(): ?array
    {
        $userId = session('backoffice.user_id');
        $user = $userId ? User::find($userId) : null;

        if ($user === null) {
            return self::NO_ACCESS;
        }

        return $this->locationScope($user);
    }

    /**
     * business_owner always has full access — several BackOffice actions
     * (managing roles, workflow settings, the destructive one-time resets)
     * are owner-only regardless of any custom permission a manager role
     * might be granted. Reads the role straight off the session rather than
     * currentLocationScope()'s User-lookup path, matching how every
     * controller that used to duplicate this check already read it.
     */
    public function isBusinessOwner(): bool
    {
        return session('backoffice.role') === 'business_owner';
    }

    /**
     * business_owner always has full access and cannot be restricted — the
     * same rule the till app's Role Permissions screen already enforces
     * (permission_provider.dart: "Business Owner ... cannot be restricted"),
     * kept consistent here rather than reinvented.
     */
    public function can(?string $businessId, ?string $role, string $permission): bool
    {
        if ($role === 'business_owner') {
            return true;
        }

        if ($businessId === null || $role === null) {
            return false;
        }

        $row = BackOfficeRolePermission::where('business_id', $businessId)
            ->where('role', $role)
            ->first();

        // No row yet = this role has never been customized for this
        // business — fall back to the pre-customization default so existing
        // businesses see no behavior change until they open Roles & opt in.
        $granted = $row?->permissions_json ?? BackOfficePermission::defaultsFor($role);

        return in_array($permission, $granted, true);
    }

    /**
     * Locations this user is restricted to, or null when unrestricted (sees
     * every location for their business — today's behavior for everyone,
     * unchanged, until an owner/manager explicitly scopes a user). A null
     * $user fails closed (NO_ACCESS) — prefer currentLocationScope() from a
     * controller, which resolves the session's user itself; this overload
     * stays defensive in case a null user is ever passed here directly by
     * some future caller instead of through that path.
     *
     * @return list<string>|null
     */
    public function locationScope(?User $user): ?array
    {
        if ($user === null) {
            return self::NO_ACCESS;
        }

        $ids = $user->locations()->pluck('locations.id')->all();

        return $ids === [] ? null : $ids;
    }
}
