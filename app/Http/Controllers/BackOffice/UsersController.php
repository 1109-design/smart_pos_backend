<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
        }

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
}
