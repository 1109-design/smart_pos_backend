<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class BackOfficeAccessController extends Controller
{
    /**
     * Everything a device needs to show the owner how to reach the BackOffice:
     * the portal URL and the business pairing code.
     */
    public function info(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);
        $tenant = $device ? Tenant::find($device->tenant_id) : null;

        if (! $tenant) {
            return response()->json(['message' => 'Device is not paired to a business.'], 403);
        }

        return response()->json([
            'portal_url' => rtrim(config('app.url'), '/').'/office',
            'pairing_code' => $tenant->pairing_code,
            'business_name' => $tenant->business_name,
        ]);
    }

    /**
     * Set (or reset) a user's BackOffice password from a paired till. The
     * device token vouches for physical access to the business's till; only
     * owner/manager accounts may hold portal passwords.
     */
    public function setPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|string',
            'password' => 'required|string|min:8|max:255',
        ]);

        $device = $this->resolveDevice($request);
        if (! $device) {
            return response()->json(['message' => 'Device is not paired to a business.'], 403);
        }

        $user = User::where('id', $data['user_id'])
            ->where('business_id', $device->tenant_id)
            ->first();

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'User not found in this business.'], 404);
        }

        $allowedRoles = ['business_owner', 'manager'];
        $role = $user->roles->first()?->name ?? $user->role ?? null;
        if (! in_array($role, $allowedRoles, true)) {
            return response()->json([
                'message' => 'Only owner and manager accounts can access the Back Office.',
            ], 403);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => 'Back Office password updated.']);
    }

    private function resolveDevice(Request $request): ?Device
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        $tokenId = explode('|', $token)[0] ?? null;

        return Device::where('token_id', $tokenId)->first();
    }
}
