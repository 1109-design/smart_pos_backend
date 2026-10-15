<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Accounting\TakeOnBatch;
use App\Services\DeviceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Keeps the original file a take-on batch was imported from (Phase 3), for
 * the batch audit. The device uploads it after the batch has synced; the
 * file is stored privately and never served to devices.
 */
class TakeOnFileController extends Controller
{
    public function __construct(private readonly DeviceResolver $deviceResolver) {}

    public function store(Request $request, string $batchId): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:20480'],
        ]);

        $device = $this->deviceResolver->fromRequest($request);
        if (! $device) {
            return response()->json(['message' => 'Device is not paired to a business.'], 403);
        }

        $batch = TakeOnBatch::find($batchId);
        if ($batch === null) {
            return response()->json(['message' => 'The batch has not synced yet.'], 404);
        }
        if ((string) $batch->business_id !== (string) $device->tenant_id) {
            return response()->json(['message' => 'This batch belongs to another business.'], 403);
        }

        $name = $batch->file_name ?: $data['file']->getClientOriginalName();
        $path = $data['file']->storeAs("take-on/{$batch->business_id}/{$batch->id}", basename($name), 'local');
        $batch->update(['file_path' => $path]);

        return response()->json(['file_path' => $path]);
    }
}
