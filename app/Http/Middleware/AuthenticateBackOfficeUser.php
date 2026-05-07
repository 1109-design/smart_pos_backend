<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBackOfficeUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = session('backoffice');

        if (empty($session['tenant_id']) || empty($session['user_id'])) {
            return redirect()->route('office.login');
        }

        $tenant = Tenant::find($session['tenant_id']);

        if (! $tenant) {
            session()->forget('backoffice');

            return redirect()->route('office.login');
        }

        tenancy()->initialize($tenant);

        $user = User::find($session['user_id']);

        if (! $user || ! $user->is_active) {
            tenancy()->end();
            session()->forget('backoffice');

            return redirect()->route('office.login')->withErrors(['email' => 'Account is inactive or no longer exists.']);
        }

        $request->attributes->set('backoffice_tenant', $tenant);
        $request->attributes->set('backoffice_user', $user);
        $request->attributes->set('backoffice_role', $session['role'] ?? null);

        $response = $next($request);

        tenancy()->end();

        return $response;
    }
}
