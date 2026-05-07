<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('BackOffice/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pairing_code' => 'required|string|max:20',
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $tenant = Tenant::where('pairing_code', strtoupper(trim($data['pairing_code'])))
            ->where('is_active', true)
            ->first();

        if (! $tenant) {
            return back()->withErrors(['pairing_code' => 'Business not found or inactive.']);
        }

        tenancy()->initialize($tenant);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            tenancy()->end();

            return back()->withErrors(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->is_active) {
            tenancy()->end();

            return back()->withErrors(['email' => 'Your account has been deactivated.']);
        }

        $role = $user->roles->first()?->name;

        tenancy()->end();

        session()->regenerate();

        session([
            'backoffice' => [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'role' => $role,
                'business_name' => $tenant->business_name,
                'currency_code' => $tenant->currency_code,
            ],
        ]);

        return redirect()->route('office.dashboard');
    }

    public function destroy(): RedirectResponse
    {
        session()->forget('backoffice');

        return redirect()->route('office.login');
    }
}
