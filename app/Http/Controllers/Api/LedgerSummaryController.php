<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\IntegrityCheckService;
use App\Services\DeviceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The ledger month by month (Phase 12.1), read-only — what a device
 * compares its own copy of the books to.
 */
class LedgerSummaryController extends Controller
{
    public function __construct(private readonly DeviceResolver $deviceResolver) {}

    public function show(Request $request, IntegrityCheckService $service): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
        if (! $device) {
            return response()->json(['message' => 'Device is not paired to a business.'], 403);
        }

        return response()->json(['months' => (object) $service->ledgerSummary((string) $device->tenant_id)]);
    }
}
