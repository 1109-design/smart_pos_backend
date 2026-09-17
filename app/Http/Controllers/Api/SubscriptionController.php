<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivationCode;
use App\Models\Location;
use App\Models\RolePermission;
use App\Models\Setting;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use App\Services\DeviceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    public function __construct(private readonly DeviceResolver $deviceResolver) {}

    /**
     * Heartbeat — the device calls this periodically (piggybacked on sync)
     * to re-confirm its entitlement while online. The Flutter app persists
     * the result and uses it to enforce the offline grace period / monthly
     * revalidation requirement even when the server can't be reached.
     */
    public function status(Request $request): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);

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
            // Manual renewal details (EcoCash + WhatsApp) — the app caches
            // these so the lock screen can show them even when offline.
            'payment_info' => Setting::paymentInfo(),
            // Assigned via the web portal (Devices page). Null means "no
            // restriction" — the app's own location picker still applies.
            // Piggybacked on this existing heartbeat rather than a new
            // endpoint so it's picked up on the same cadence as everything
            // else the device already polls for.
            'assigned_location_id' => $device->location_id,
            'assigned_location_name' => $device->location?->name,
        ]);
    }

    /**
     * Lets the device rename itself (e.g. "Till 1 — Front Counter") so
     * cashiers can tell which physical device they're trading on. The web
     * portal's Devices page can also rename it centrally — whichever was
     * set most recently wins, same as any other synced field.
     */
    public function updateName(Request $request): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);

        if (! $device || $device->is_revoked) {
            return response()->json(['message' => 'Device revoked.'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $device->update(['name' => $data['name']]);

        return response()->json(['name' => $device->name]);
    }

    /**
     * Reports the location a cashier picked on-device (the fallback prompt
     * shown when the admin hasn't assigned one from the web portal). Without
     * this, that choice lived only in the device's local storage — the
     * portal's Devices page couldn't tell "genuinely unrestricted" apart
     * from "a cashier picked X three weeks ago and it was never audited".
     *
     * Deliberately only writes when the device is *currently* unassigned:
     * if an admin locks a location from the portal between the device
     * fetching its heartbeat and this call landing, that lock must win, not
     * a self-pick that's already stale by the time it arrives.
     *
     * `force: true` is the one exception: an Owner/Manager (or a Cashier
     * explicitly granted the override permission — see
     * permission_provider.dart's Permission.overrideLocationLock)
     * re-pointing an already-locked device straight from the app instead of
     * the web portal. That re-points the assignment itself, not just this
     * session's pick, so it doesn't get flipped back on the next heartbeat.
     *
     * The device's bearer token has no notion of which staff member is
     * *currently* signed in on it — cashiers switch on-device via local PIN
     * without a fresh server login — so this can only re-check the role of
     * whoever last authenticated this device with the server, same
     * approximation SyncProcessor::handleUpsert() already relies on for its
     * own manage_tills gate. That's still a real server-side floor: a bare
     * holder of the bearer token can no longer force an override the last
     * authenticated role was never granted, closing the gap where `force`
     * used to be honored purely on the client's say-so.
     */
    public function reportSelfPickedLocation(Request $request): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);

        if (! $device || $device->is_revoked) {
            return response()->json(['message' => 'Device revoked.'], 403);
        }

        $data = $request->validate([
            'location_id' => 'required|uuid|exists:locations,id',
            'force' => 'sometimes|boolean',
        ]);

        $belongsToBusiness = Location::where('id', $data['location_id'])
            ->where('business_id', $device->tenant_id)
            ->exists();
        abort_unless($belongsToBusiness, 403);

        $force = $data['force'] ?? false;
        if ($force && ! $this->canOverrideLocationLock($request, $device->tenant_id)) {
            Log::warning('Ignored unauthorized force:true device-location override', [
                'device_id' => $device->id,
                'tenant_id' => $device->tenant_id,
                'attempted_location_id' => $data['location_id'],
                'acting_user_id' => $request->user()?->id,
            ]);
            $force = false;
        }

        if ($device->location_id === null || $force) {
            $device->update(['location_id' => $data['location_id']]);
        }

        return response()->json(['location_id' => $device->location_id]);
    }

    /**
     * Mirrors the till app's own (offline-editable) role_permissions table
     * rather than the BackOffice-only backoffice_role_permissions one — see
     * BackOfficePermission's class docblock for why those two are kept
     * deliberately separate. Falls back to permission_provider.dart's own
     * built-in defaults (Owner and Manager get this out of the box; every
     * other role needs it explicitly granted) when no customization for
     * this role has ever synced up from a device.
     */
    private function canOverrideLocationLock(Request $request, string $tenantId): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        // The Dart UserRole.owner.key is 'owner', not Spatie's
        // 'business_owner' — but the till app's own permission check
        // (permission_provider.dart's permissionProvider) already grants
        // Owner this unconditionally, matched here rather than looked up.
        $role = $user->getRoleNames()->first();
        if ($role === 'business_owner') {
            return true;
        }

        $row = RolePermission::where('business_id', $tenantId)->where('role', $role)->first();
        if ($row !== null) {
            return in_array('overrideLocationLock', $row->permissions_json ?? [], true);
        }

        return $role === 'manager';
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

        $device = $this->deviceResolver->fromRequest($request);

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
}
