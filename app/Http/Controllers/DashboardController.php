<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'total_businesses'    => Tenant::count(),
                'active_devices'      => Device::where('is_revoked', false)->count(),
                'pro_businesses'      => Tenant::where('tier', 'pro')->count(),
                'ultimate_businesses' => Tenant::where('tier', 'ultimate')->count(),
            ],
            'recent_businesses' => Tenant::withCount('devices')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
        ]);
    }
}
