<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeviceResolver;
use App\Services\SyncHealthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only reconciliation visibility (spec §28: "are all devices
 * synchronized?"). Deliberately its own controller/file rather than an
 * addition to SyncController — keeps this reporting-only feature isolated
 * from the record-processing pipeline other work concurrently touches.
 */
class SyncHealthController extends Controller
{
    public function __construct(private readonly DeviceResolver $deviceResolver) {}

    public function index(Request $request, SyncHealthReport $report): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
        $tenantId = $device?->tenant_id ?? $request->query('business_id');

        if (! $tenantId) {
            return response()->json(['devices' => [], 'integrity' => []]);
        }

        return response()->json([
            'devices' => $report->devicesFor($tenantId),
            'integrity' => $report->integrityFor($tenantId),
        ]);
    }
}
