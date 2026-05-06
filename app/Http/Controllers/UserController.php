<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(string $business): Response
    {
        $tenant = Tenant::findOrFail($business);

        // Initialize tenancy to query tenant DB
        tenancy()->initialize($tenant);

        $users = User::with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn($u) => [
                'id'        => $u->id,
                'name'      => $u->name,
                'email'     => $u->email,
                'is_active' => $u->is_active,
                'role'      => $u->roles->first()?->name,
            ]);

        tenancy()->end();

        return Inertia::render('Users/Index', [
            'business' => $tenant,
            'users'    => $users,
            'roles'    => ['business_owner', 'manager', 'cashier'],
        ]);
    }

    public function store(Request $request, string $business): RedirectResponse
    {
        $tenant = Tenant::findOrFail($business);

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255',
            'password' => 'required|string|min:8',
            'role'     => 'required|in:business_owner,manager,cashier',
            'pin'      => 'nullable|digits:4',
        ]);

        tenancy()->initialize($tenant);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'pin_hash' => isset($data['pin']) ? Hash::make($data['pin']) : null,
            'is_active' => true,
        ]);

        $user->assignRole($data['role']);

        tenancy()->end();

        return redirect()->route('businesses.users.index', $business)
            ->with('success', 'User created.');
    }

    public function update(Request $request, string $business, string $userId): RedirectResponse
    {
        $tenant = Tenant::findOrFail($business);

        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|max:255',
            'role'      => 'required|in:business_owner,manager,cashier',
            'is_active' => 'boolean',
            'pin'       => 'nullable|digits:4',
        ]);

        tenancy()->initialize($tenant);

        $user = User::findOrFail($userId);
        $user->update([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'is_active' => $data['is_active'] ?? $user->is_active,
            'pin_hash'  => isset($data['pin']) ? Hash::make($data['pin']) : $user->pin_hash,
        ]);
        $user->syncRoles([$data['role']]);

        tenancy()->end();

        return redirect()->route('businesses.users.index', $business)
            ->with('success', 'User updated.');
    }

    public function destroy(string $business, string $userId): RedirectResponse
    {
        $tenant = Tenant::findOrFail($business);

        tenancy()->initialize($tenant);
        User::findOrFail($userId)->delete();
        tenancy()->end();

        return redirect()->route('businesses.users.index', $business)
            ->with('success', 'User removed.');
    }
}
