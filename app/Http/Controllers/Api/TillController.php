<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\DeviceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TillController extends Controller
{
    public function __construct(private readonly DeviceResolver $deviceResolver) {}

    /**
     * Tills registered at a location, for the till-picker shown when a
     * cashier opens a shift. Till creation/edits flow through the normal
     * sync/push path (mirroring how a self-picked location is reported),
     * so there is no store/update endpoint here.
     */
    public function index(Request $request, string $locationId): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);

        $location = Location::where('business_id', $device?->tenant_id)->findOrFail($locationId);

        $tills = $location->tills()
            ->where('is_active', true)
            ->orderBy('register_number')
            ->get();

        return response()->json(['data' => $tills]);
    }
}
