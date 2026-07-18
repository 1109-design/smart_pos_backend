<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the central admin panel to platform administrators.
 *
 * Business users live in the same users table (single-database tenancy) but
 * always carry a business_id; platform admins have none. Without this check
 * a business owner who knows their web password could sign in at /login and
 * gain the full super-admin panel — tiers, every business, activation codes.
 */
class EnsurePlatformAdmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->business_id !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('office.login')->withErrors([
                'email' => 'Business accounts sign in here, at the Back Office portal.',
            ]);
        }

        return $next($request);
    }
}
