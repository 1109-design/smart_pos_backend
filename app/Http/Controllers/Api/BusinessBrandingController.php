<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\BusinessBrandingService;
use App\Services\DeviceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessBrandingController extends Controller
{
    public function __construct(private readonly DeviceResolver $deviceResolver) {}

    /**
     * A device uploads whatever logo it picked locally (see
     * business_profile_screen.dart's _pickLogo()) so every other device
     * and the BackOffice web app can show the same one. See
     * App\Http\Controllers\BackOffice\SettingsController::uploadBrandingLogo()
     * for the BackOffice counterpart.
     */
    public function uploadLogo(Request $request, BusinessBrandingService $service): JsonResponse
    {
        $data = $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $device = $this->deviceResolver->fromRequest($request);
        if (! $device) {
            return response()->json(['message' => 'Device is not paired to a business.'], 403);
        }

        $business = Business::find($device->tenant_id);
        if (! $business) {
            return response()->json(['message' => 'Business not found.'], 404);
        }

        $service->uploadLogo($business, $data['logo']);

        return response()->json(['logo_url' => $business->logoUrl()]);
    }
}
