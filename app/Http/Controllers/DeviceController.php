<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceController extends Controller
{
    public function index(string $business): Response
    {
        $tenant = Tenant::findOrFail($business);
        $devices = $tenant->devices()->orderByDesc('created_at')->get();

        return Inertia::render('Devices/Index', [
            'business' => $tenant,
            'devices'  => $devices,
        ]);
    }

    public function revoke(string $business, Device $device): RedirectResponse
    {
        abort_if($device->tenant_id !== $business, 403);

        // Revoke the Sanctum token
        if ($device->token_id) {
            PersonalAccessToken::find($device->token_id)?->delete();
        }

        $device->update(['is_revoked' => true, 'token_id' => null]);

        return back()->with('success', 'Device revoked.');
    }

    public function destroy(string $business, Device $device): RedirectResponse
    {
        abort_if($device->tenant_id !== $business, 403);

        if ($device->token_id) {
            PersonalAccessToken::find($device->token_id)?->delete();
        }

        $device->delete();

        return redirect()->route('businesses.devices.index', $business)
            ->with('success', 'Device removed.');
    }
}
