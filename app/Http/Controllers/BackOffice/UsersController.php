<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\BackOfficeRolePermission;
use App\Models\Location;
use App\Models\SyncRecord;
use App\Models\User;
use App\Services\BackOfficeAuthorizer;
use App\Services\SyncProcessor;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UsersController extends BackOfficeController
{
    public function __construct(private readonly BackOfficeAuthorizer $authorizer) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim();
        $role = session('backoffice.role');

        $this->authorizeManager();

        $users = User::with(['roles', 'locations:id,name'])
            ->when($search, fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
            )
            ->orderBy('name')
            ->get()
            ->filter(fn ($u) => $u->email !== 'admin@smartpos.app' &&
                $u->roles->first()?->name !== 'super_admin'
            )
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => $u->is_active,
                'role' => $u->roles->first()?->name,
                'location_ids' => $u->locations->pluck('id'),
                'location_names' => $u->locations->pluck('name'),
            ])
            ->values();

        return Inertia::render('BackOffice/Users', [
            'users' => $users,
            // Mirrors assignableRoles()'s own owner-only restriction so a
            // manager's Add/Edit User form never even offers business_owner
            // as an option, not just rejects it server-side.
            'roles' => collect($this->assignableRoles())->values(),
            'locations' => Location::where('business_id', $this->tenantId())
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'viewer_role' => $role,
            'filters' => ['search' => $search->toString()],
        ]);
    }

    public function store(Request $request, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'role' => ['required', Rule::in($this->assignableRoles())],
            'pin' => 'required|digits:4',
        ]);

        $this->ensureRoleExists($data['role']);

        // Routed through the same pipeline a device push uses (not a plain
        // User::create()) so a web-created user shows up on every till on
        // its next sync exactly like one created at the counter, and picks
        // up the same PIN-hashing safety net as every other write path.
        $uuid = (string) Str::uuid();
        $payload = [
            'business_id' => $this->tenantId(),
            'name' => $data['name'],
            'email' => $data['email'],
            'pin_hash' => Hash::make($data['pin']),
            'role' => $data['role'],
            'is_active' => true,
            'biometric_enabled' => false,
        ];

        $processor->process('users', $uuid, 'upsert', $payload);
        $this->publishSyncRecord($uuid, $payload);

        return redirect()->route('office.users.index')
            ->with('success', 'User created. They can sign in at the till once it syncs — Back Office access needs a password set separately via "Password".');
    }

    public function update(Request $request, string $userId): RedirectResponse
    {
        $this->authorizeManager();

        $currentUserId = session('backoffice.user_id');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'role' => ['required', Rule::in($this->assignableRoles())],
            'is_active' => 'boolean',
            'pin' => 'nullable|digits:4',
        ]);

        $user = User::findOrFail($userId);

        // Prevent demoting/deactivating your own account
        if ($user->id === $currentUserId) {
            unset($data['role'], $data['is_active']);
        }

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'is_active' => $data['is_active'] ?? $user->is_active,
            'pin_hash' => isset($data['pin']) ? Hash::make($data['pin']) : $user->pin_hash,
        ]);

        if (isset($data['role'])) {
            $this->ensureRoleExists($data['role']);
            $user->syncRoles([$data['role']]);
            $roleName = $data['role'];
        } else {
            $roleName = $user->roles->first()?->name ?? 'cashier';
        }

        SyncRecord::create([
            'business_id' => $user->business_id,
            'table_name' => 'users',
            'record_uuid' => $user->id,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $user->business_id,
                'name' => $user->name,
                'email' => $user->email,
                'pin_hash' => $user->pin_hash,
                'role' => $roleName === 'business_owner' ? 'owner' : $roleName,
                'is_active' => (bool) $user->is_active,
                'biometric_enabled' => false,
            ],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);

        return redirect()->route('office.users.index')
            ->with('success', 'User updated.');
    }

    public function changePassword(Request $request, string $userId): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'password' => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        User::findOrFail($userId)->update(['password' => Hash::make($data['password'])]);

        return redirect()->route('office.users.index')
            ->with('success', 'Password updated.');
    }

    /**
     * Scope a user to specific locations — empty selection means
     * unrestricted (sees every location), the default for every user until
     * an owner/manager deliberately narrows it here.
     */
    public function updateLocations(Request $request, string $userId): RedirectResponse
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();
        $user = User::where('business_id', $tenantId)->findOrFail($userId);

        $data = $request->validate([
            'location_ids' => ['array'],
            'location_ids.*' => ['string', Rule::exists('locations', 'id')->where('business_id', $tenantId)],
        ]);

        $user->locations()->sync($data['location_ids'] ?? []);

        return back()->with('success', 'User location access updated.');
    }

    public function toggleActive(string $userId): RedirectResponse
    {
        $this->authorizeManager();

        $currentUserId = session('backoffice.user_id');
        $user = User::findOrFail($userId);

        abort_if($user->id === $currentUserId, 403, 'You cannot deactivate your own account.');

        $user->update(['is_active' => ! $user->is_active]);

        $roleName = $user->roles->first()?->name ?? 'cashier';

        SyncRecord::create([
            'business_id' => $user->business_id,
            'table_name' => 'users',
            'record_uuid' => $user->id,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $user->business_id,
                'name' => $user->name,
                'email' => $user->email,
                'pin_hash' => $user->pin_hash,
                'role' => $roleName === 'business_owner' ? 'owner' : $roleName,
                'is_active' => (bool) $user->is_active,
                'biometric_enabled' => false,
            ],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);

        return redirect()->route('office.users.index')
            ->with('success', $user->is_active ? 'User activated.' : 'User deactivated.');
    }

    private function authorizeManager(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_USERS),
            403,
            'Access denied.'
        );
    }

    /**
     * The 3 built-in roles plus any custom role this business has defined
     * via Roles & Permissions — a role must be created there before it's
     * assignable to a user, so "assignable" and "has a permission set" never
     * drift apart.
     *
     * business_owner is deliberately excluded unless the ACTING session is
     * itself an owner — otherwise a manager (who has MANAGE_USERS by
     * default) could promote a user, including themselves, to unrestricted
     * owner access. This is the one place a role is actually assigned to a
     * user, so it's the one place that invariant must be enforced — every
     * other file (RolesController, BackOfficeAuthorizer) only assumes it.
     *
     * @return list<string>
     */
    private function assignableRoles(): array
    {
        $custom = BackOfficeRolePermission::where('business_id', $this->tenantId())->pluck('role');

        $builtins = session('backoffice.role') === 'business_owner'
            ? ['business_owner', 'manager', 'cashier']
            : ['manager', 'cashier'];

        return collect($builtins)->merge($custom)->unique()->values()->all();
    }

    /**
     * Spatie roles are a global (name, guard) pool with tenancy off — see
     * BackOfficeAuthorizer for why that's still tenant-safe here (permissions
     * are resolved from BackOfficeRolePermission, keyed by business_id, never
     * from Spatie's own role-to-permission tables). This just guarantees the
     * label row exists before assignRole()/syncRoles() looks it up.
     */
    private function ensureRoleExists(string $role): void
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publishSyncRecord(string $uuid, array $payload): void
    {
        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? $this->tenantId(),
            'table_name' => 'users',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
