<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\StockResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockResetController extends Controller
{
    /**
     * A till's own "Reset All Stock" button calls this first, before doing
     * anything locally. It only claims the business's one-time token —
     * zeroing stock stays entirely on-device (the same way opening stock is
     * seeded, see product_form_screen.dart) and reaches the cloud through the
     * normal /v1/sync/push pipeline, not through this endpoint. See
     * App\Http\Controllers\BackOffice\SettingsController::resetStock() for
     * the counterpart that both claims *and* zeroes in one step, and
     * [[smartpos-stock-reset]] for why the two origins differ here.
     *
     * user_id is the till's currently logged-in staff member, not necessarily
     * whoever the device's own pairing token belongs to — devices are shared
     * across a shift, same as App\Http\Controllers\Api\BackOfficeAccessController::setPassword().
     */
    public function claim(Request $request, StockResetService $service): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string'],
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

        $role = $user->roles->first()?->name;
        if ($role !== 'business_owner') {
            return response()->json(['message' => 'Only the business owner can do this.'], 403);
        }

        $claim = $service->claim($device->tenant_id, $user->id);

        if (! $claim['claimed']) {
            return response()->json([
                'message' => 'Stock has already been reset once for this business.',
                'at' => $claim['at']?->toIso8601String(),
            ], 409);
        }

        return response()->json([
            'claimed' => true,
            'at' => $claim['at']?->toIso8601String(),
        ]);
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
