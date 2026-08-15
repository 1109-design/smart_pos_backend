<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        // Asset-version cache-busting depends on whatever happens to be built
        // in public/build on the machine running the suite — that's incidental
        // state, not something tests should be sensitive to.
        if (app()->environment('testing')) {
            return null;
        }

        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $backoffice = session('backoffice');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'import_errors' => $request->session()->get('import_errors'),
            ],
            'backoffice_auth' => $backoffice ? [
                'user_name' => $backoffice['user_name'],
                'user_email' => $backoffice['user_email'],
                'role' => $backoffice['role'],
                'business_name' => $backoffice['business_name'],
                'currency_code' => $backoffice['currency_code'],
            ] : null,
        ];
    }
}
