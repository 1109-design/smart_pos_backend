<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Http\Request;

class DeviceResolver
{
    /** Resolve the authenticated request's Device row from its Sanctum bearer token. */
    public function fromRequest(Request $request): ?Device
    {
        return $this->fromBearerToken($request->bearerToken());
    }

    public function fromBearerToken(?string $token): ?Device
    {
        if (! $token) {
            return null;
        }

        $tokenId = explode('|', $token)[0] ?? null;

        return Device::where('token_id', $tokenId)->first();
    }
}
