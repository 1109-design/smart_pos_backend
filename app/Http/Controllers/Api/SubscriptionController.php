<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivationCode;
use App\Models\Device;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    /**
     * Heartbeat — the device calls this periodically (piggybacked on sync)
     * to re-confirm its entitlement while online. The Flutter app persists
     * the result and uses it to enforce the offline grace period / monthly
     * revalidation requirement even when the server can't be reached.
     */
    public function status(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);

        if (! $device || $device->is_revoked) {
            return response()->json(['message' => 'Device revoked.'], 403);
        }

        $tenant = Tenant::find($device->tenant_id);

        if (! $tenant || ! $tenant->is_active) {
            return response()->json(['message' => 'Business not found or inactive.'], 404);
        }

        $device->update(['last_seen_at' => now()]);

        // Sliding expiration: each successful heartbeat pushes the token's
        // expiry out again, so a device that checks in monthly (as required)
        // never hits the hard 90-day token cutoff and gets stranded while
        // fully paid. A device offline past 90 days must re-login with PIN.
        $request->user()?->currentAccessToken()?->forceFill([
            'expires_at' => now()->addDays(90),
        ])->save();

        return response()->json([
            'tier' => $tenant->tier,
            'subscription_valid_until' => $tenant->subscription_valid_until?->toIso8601String(),
            'is_active' => $tenant->isSubscriptionActive(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Redeems an activation code against the device's already-paired session
     * (e.g. after a merchant pays via EcoCash and is texted a code). Unlike
     * DeviceAuthController::activate, this does not mint a new token — the
     * device is already authenticated; this only updates entitlement.
     */
    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'activation_code' => 'required|string|max:16',
        ]);

        $device = $this->resolveDevice($request);

        if (! $device || $device->is_revoked) {
            return response()->json(['message' => 'Device revoked.'], 403);
        }

        $tenant = Tenant::find($device->tenant_id);

        if (! $tenant || ! $tenant->is_active) {
            return response()->json(['message' => 'Business not found or inactive.'], 404);
        }

        $code = ActivationCode::where('code', strtoupper($data['activation_code']))
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $code || ! $code->isValid()) {
            return response()->json(['message' => 'Invalid or expired activation code.'], 400);
        }

        $previousTier = $tenant->tier;

        $tenant->update([
            'tier' => $code->tier,
            'subscription_valid_until' => $code->expires_at,
        ]);

        $code->update([
            'device_id' => $device->id,
            'used_at' => now(),
            'status' => 'used',
        ]);

        $device->update(['last_seen_at' => now()]);

        SubscriptionHistory::create([
            'tenant_id' => $tenant->id,
            'tier' => $code->tier,
            'event_type' => 'ACTIVATION_REDEEMED',
            'previous_tier' => $previousTier !== $code->tier ? $previousTier : null,
            'subscription_valid_until' => $code->expires_at,
            'metadata' => [
                'activation_code_id' => $code->id,
                'redeemed_by_device_id' => $device->id,
            ],
        ]);

        return response()->json([
            'tier' => $tenant->tier,
            'subscription_valid_until' => $tenant->subscription_valid_until?->toIso8601String(),
            'is_active' => $tenant->isSubscriptionActive(),
            'server_time' => now()->toIso8601String(),
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
