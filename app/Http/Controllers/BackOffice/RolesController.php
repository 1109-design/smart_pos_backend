<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\BackOfficeRolePermission;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RolesController extends BackOfficeController
{
    public function __construct(private readonly BackOfficeAuthorizer $authorizer) {}

    public function index(): Response
    {
        $this->authorizeOwner();

        $tenantId = $this->tenantId();
        $rows = BackOfficeRolePermission::where('business_id', $tenantId)->get()->keyBy('role');

        // business_owner is deliberately excluded — it always has full
        // access and cannot be restricted (same rule the till app's Role
        // Permissions screen already enforces).
        $roleNames = collect(['manager', 'cashier'])
            ->merge($rows->keys()->reject(fn (string $r) => $r === 'business_owner'))
            ->unique()
            ->values();

        $roles = $roleNames->map(fn (string $name) => [
            'name' => $name,
            'is_builtin' => in_array($name, ['manager', 'cashier'], true),
            'permissions' => $rows->get($name)?->permissions_json ?? BackOfficePermission::defaultsFor($name),
        ]);

        return Inertia::render('BackOffice/Roles', [
            'roles' => $roles,
            'permission_catalogue' => collect(BackOfficePermission::all())->map(fn (string $p) => [
                'key' => $p,
                'label' => BackOfficePermission::label($p),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeOwner();

        $tenantId = $this->tenantId();

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100', 'alpha_dash',
                Rule::notIn(['business_owner', 'manager', 'cashier']),
                Rule::unique('backoffice_role_permissions', 'role')->where('business_id', $tenantId),
            ],
        ]);

        // Creating the row here (even with an empty permission set) is what
        // makes the role "exist" for this business — UsersController only
        // allows assigning roles that have a row here (or are built-in).
        BackOfficeRolePermission::create([
            'business_id' => $tenantId,
            'role' => $data['name'],
            'permissions_json' => [],
        ]);

        // The label must exist before a user can be assigned this role —
        // see BackOfficeAuthorizer for why a shared global (name, guard)
        // pool is still tenant-safe (permissions never come from Spatie's
        // own tables, only from BackOfficeRolePermission keyed by business).
        Role::firstOrCreate(['name' => $data['name'], 'guard_name' => 'web']);

        return back()->with('success', "Role \"{$data['name']}\" created.");
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        $this->authorizeOwner();

        abort_if($role === 'business_owner', 403, 'Business Owner always has full access and cannot be restricted.');

        // This role must already exist — either a builtin or a row store()
        // already validated (alpha_dash, reserved-name exclusion, per-
        // business uniqueness) — never silently minted here via
        // updateOrCreate() from an unvalidated URL segment. Without this,
        // PUT /office/roles/{anything} would create a brand-new,
        // unvalidated-format role that store() would have rejected outright.
        $tenantId = $this->tenantId();
        $isBuiltin = in_array($role, ['manager', 'cashier'], true);
        abort_unless(
            $isBuiltin || BackOfficeRolePermission::where('business_id', $tenantId)->where('role', $role)->exists(),
            404
        );

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(BackOfficePermission::all())],
        ]);

        BackOfficeRolePermission::updateOrCreate(
            ['business_id' => $tenantId, 'role' => $role],
            ['permissions_json' => array_values($data['permissions'] ?? [])]
        );

        return back()->with('success', "Permissions for \"{$role}\" updated.");
    }

    private function authorizeOwner(): void
    {
        abort_unless($this->authorizer->isBusinessOwner(), 403, 'Only the business owner can manage roles.');
    }
}
