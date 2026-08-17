<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceController extends Controller
{
    public function index(string $business): Response
    {
        $tenant = Tenant::findOrFail($business);
        $devices = $tenant->devices()->orderByDesc('created_at')->get();
        $locations = Location::where('business_id', $business)
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        return Inertia::render('Devices/Index', [
            'business' => $tenant,
            'devices' => $devices,
            'locations' => $locations,
        ]);
    }

    /** Rename a device — e.g. "Till 1 — Front Counter" — so it's recognizable both here and on the till's own screen. */
    public function rename(Request $request, string $business, Device $device): RedirectResponse
    {
        abort_if($device->tenant_id !== $business, 403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $device->update(['name' => $data['name']]);

        return back()->with('success', 'Device renamed.');
    }

    /** Assign (or clear) the shop/warehouse this device operates from. */
    public function assignLocation(Request $request, string $business, Device $device): RedirectResponse
    {
        abort_if($device->tenant_id !== $business, 403);

        $data = $request->validate([
            'location_id' => 'nullable|uuid|exists:locations,id',
        ]);

        if ($data['location_id'] !== null) {
            $belongsToBusiness = Location::where('id', $data['location_id'])
                ->where('business_id', $business)
                ->exists();
            abort_unless($belongsToBusiness, 403);
        }

        $device->update(['location_id' => $data['location_id']]);

        return back()->with('success', 'Device location updated.');
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
