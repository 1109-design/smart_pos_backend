<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivationCode;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class DeviceAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_identifier' => 'required|uuid',
            'device_name'       => 'required|string|max:255',
            'pin'               => 'required|digits:4',
            'business_code'     => 'required|string',
        ]);

        // Resolve tenant by pairing_code or ID (business_code)
        $tenant = Tenant::where('pairing_code', $data['business_code'])
            ->orWhere('id', $data['business_code'])
            ->first();

        if (! $tenant || ! $tenant->is_active) {
            return response()->json(['message' => 'Business not found or inactive.'], 404);
        }

        if (! $tenant->isSubscriptionActive()) {
            return response()->json(['message' => 'Subscription expired.'], 402);
        }

        // Find user by PIN in tenant context
        tenancy()->initialize($tenant);

        $user = User::where('is_active', true)->get()->first(
            fn($u) => $u->pin_hash && Hash::check($data['pin'], $u->pin_hash)
        );

        if (! $user) {
            tenancy()->end();
            return response()->json(['message' => 'Invalid PIN.'], 401);
        }

        tenancy()->end();

        // Register or update device in central DB
        $device = Device::firstOrNew([
            'tenant_id'         => $tenant->id,
            'device_identifier' => $data['device_identifier'],
        ]);

        // Revoke old token if exists
        if ($device->token_id) {
            $user->tokens()->where('id', $device->token_id)->delete();
        }

        $token = $user->createToken($data['device_name'], ['*'], now()->addDays(90));

        $device->fill([
            'name'        => $data['device_name'],
            'token_id'    => $token->accessToken->id,
            'last_seen_at' => now(),
            'is_revoked'  => false,
        ])->save();

        return response()->json([
            'token' => $token->plainTextToken,
            'user'  => [
                'id'          => $user->id,
                'name'        => $user->name,
                'role'        => $user->roles->first()?->name,
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'business' => [
                'id'            => $tenant->id,
                'name'          => $tenant->business_name,
                'tier'          => $tenant->tier,
                'currency_code' => $tenant->currency_code,
            ],
        ]);
    }

    /**
     * Initial device setup — authenticates with email + PIN.
     * Used by the mobile app on first launch.
     */
    public function setup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_identifier' => 'required|uuid',
            'device_name'       => 'required|string|max:255',
            'email'             => 'required|email',
            'pin'               => 'required|digits:4',
            'business_code'     => 'required|string',
        ]);

        $tenant = Tenant::where('pairing_code', $data['business_code'])
            ->orWhere('id', $data['business_code'])
            ->first();

        if (! $tenant || ! $tenant->is_active) {
            return response()->json(['message' => 'Business not found or inactive.'], 404);
        }

        if (! $tenant->isSubscriptionActive()) {
            return response()->json(['message' => 'Subscription expired.'], 402);
        }

        tenancy()->initialize($tenant);

        $user = User::where('email', $data['email'])->where('is_active', true)->first();

        if (! $user || ! $user->pin_hash || ! Hash::check($data['pin'], $user->pin_hash)) {
            Log::warning('Device setup rejected: invalid email or PIN.', [
                'tenant_id' => $tenant->id,
                'email' => $data['email'],
                'reason' => match (true) {
                    ! $user => 'no active user with that email in this tenant',
                    ! $user->pin_hash => 'user has no pin_hash set',
                    default => 'pin did not match stored hash',
                },
            ]);

            tenancy()->end();
            return response()->json(['message' => 'Invalid email or PIN.'], 401);
        }

        tenancy()->end();

        $device = Device::firstOrNew([
            'tenant_id'         => $tenant->id,
            'device_identifier' => $data['device_identifier'],
        ]);

        if ($device->token_id) {
            $user->tokens()->where('id', $device->token_id)->delete();
        }

        $token = $user->createToken($data['device_name'], ['*'], now()->addDays(90));

        $device->fill([
            'name'         => $data['device_name'],
            'token_id'     => $token->accessToken->id,
            'last_seen_at' => now(),
            'is_revoked'   => false,
        ])->save();

        return response()->json([
            'token' => $token->plainTextToken,
            'user'  => [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'role'        => $user->roles->first()?->name,
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'business' => [
                'id'            => $tenant->id,
                'name'          => $tenant->business_name,
                'tier'          => $tenant->tier,
                'currency_code' => $tenant->currency_code,
                'pairing_code'  => $tenant->pairing_code,
            ],
        ]);
    }

    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_identifier' => 'required|uuid',
            'device_name'       => 'required|string|max:255',
            'activation_code'   => 'required|string|max:16',
            'business_code'     => 'required|string',
        ]);

        // Resolve tenant by pairing_code or ID (business_code)
        $tenant = Tenant::where('pairing_code', $data['business_code'])
            ->orWhere('id', $data['business_code'])
            ->first();

        if (! $tenant || ! $tenant->is_active) {
            return response()->json(['message' => 'Business not found or inactive.'], 404);
        }

        // Find and validate activation code
        $activationCode = ActivationCode::where('code', $data['activation_code'])
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $activationCode || ! $activationCode->isValid()) {
            return response()->json(['message' => 'Invalid or expired activation code.'], 400);
        }

        // Find user in tenant context (use first active user for activation)
        tenancy()->initialize($tenant);

        $user = User::where('is_active', true)->first();

        if (! $user) {
            tenancy()->end();
            return response()->json(['message' => 'No active user found for this business.'], 404);
        }

        tenancy()->end();

        // Register or update device in central DB
        $device = Device::firstOrNew([
            'tenant_id'         => $tenant->id,
            'device_identifier' => $data['device_identifier'],
        ]);

        // Revoke old token if exists
        if ($device->token_id) {
            $user->tokens()->where('id', $device->token_id)->delete();
        }

        $token = $user->createToken($data['device_name'], ['*'], now()->addDays(90));

        $device->fill([
            'name'        => $data['device_name'],
            'token_id'    => $token->accessToken->id,
            'last_seen_at' => now(),
            'is_revoked'  => false,
        ])->save();

        // Mark activation code as used
        $activationCode->update([
            'device_id' => $device->id,
            'used_at'   => now(),
            'status'    => 'used',
        ]);

        return response()->json([
            'token' => $token->plainTextToken,
            'user'  => [
                'id'          => $user->id,
                'name'        => $user->name,
                'role'        => $user->roles->first()?->name,
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'business' => [
                'id'            => $tenant->id,
                'name'          => $tenant->business_name,
                'tier'          => $tenant->tier,
                'currency_code' => $tenant->currency_code,
            ],
        ]);
    }
}
