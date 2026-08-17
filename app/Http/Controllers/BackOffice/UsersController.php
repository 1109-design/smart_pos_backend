<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\SyncRecord;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UsersController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim();
        $role = session('backoffice.role');

        abort_if(! in_array($role, ['business_owner', 'manager']), 403, 'Access denied.');

        $users = User::with('roles')
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
            ])
            ->values();

        return Inertia::render('BackOffice/Users', [
            'users' => $users,
            'roles' => ['business_owner', 'manager', 'cashier'],
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
            'role' => 'required|in:business_owner,manager,cashier',
            'pin' => 'required|digits:4',
        ]);

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
            'role' => 'required|in:business_owner,manager,cashier',
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
        abort_if(
            ! in_array(session('backoffice.role'), ['business_owner', 'manager']),
            403,
            'Access denied.'
        );
    }

    private function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
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
