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

        $business = $this->resolveBusiness($request);
        if (! $business instanceof Business) {
            return $business;
        }

        $service->uploadLogo($business, $data['logo']);

        return response()->json(['logo_url' => $business->logoUrl()]);
    }

    /** Same flow as uploadLogo() above, for the client's existing letterhead artwork. */
    public function uploadLetterhead(Request $request, BusinessBrandingService $service): JsonResponse
    {
        $data = $request->validate([
            'letterhead' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        ]);

        $business = $this->resolveBusiness($request);
        if (! $business instanceof Business) {
            return $business;
        }

        $service->uploadLetterhead($business, $data['letterhead']);

        return response()->json(['letterhead_url' => $business->letterheadUrl()]);
    }

    public function uploadFooterImage(Request $request, BusinessBrandingService $service): JsonResponse
    {
        $data = $request->validate([
            'footer' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        ]);

        $business = $this->resolveBusiness($request);
        if (! $business instanceof Business) {
            return $business;
        }

        $service->uploadFooterImage($business, $data['footer']);

        return response()->json(['footer_url' => $business->footerUrl()]);
    }

    public function updateFooterText(Request $request, BusinessBrandingService $service): JsonResponse
    {
        $data = $request->validate([
            'footer_text' => ['nullable', 'string', 'max:2000'],
        ]);

        $business = $this->resolveBusiness($request);
        if (! $business instanceof Business) {
            return $business;
        }

        $service->updateFooterText($business, $data['footer_text'] ?? null);

        return response()->json(['footer_text' => $business->footer_text]);
    }

    /** Resolves the calling device's business, or a JsonResponse error to return as-is. */
    private function resolveBusiness(Request $request): Business|JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
        if (! $device) {
            return response()->json(['message' => 'Device is not paired to a business.'], 403);
        }

        $business = Business::find($device->tenant_id);
        if (! $business) {
            return response()->json(['message' => 'Business not found.'], 404);
        }

        return $business;
    }
}
