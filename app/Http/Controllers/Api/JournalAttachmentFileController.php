<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Accounting\JournalAttachment;
use App\Services\DeviceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stores the file behind a journal attachment (Phase 4). The device
 * uploads it once the attachment record has synced; it is kept privately.
 */
class JournalAttachmentFileController extends Controller
{
    public function __construct(private readonly DeviceResolver $deviceResolver) {}

    public function store(Request $request, string $attachmentId): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp,csv,txt,xlsx,xls,doc,docx', 'max:20480'],
        ]);

        $device = $this->deviceResolver->fromRequest($request);
        if (! $device) {
            return response()->json(['message' => 'Device is not paired to a business.'], 403);
        }

        $attachment = JournalAttachment::find($attachmentId);
        if ($attachment === null) {
            return response()->json(['message' => 'The attachment has not synced yet.'], 404);
        }
        if ((string) $attachment->business_id !== (string) $device->tenant_id) {
            return response()->json(['message' => 'This attachment belongs to another business.'], 403);
        }

        $path = $data['file']->storeAs(
            "journal-attachments/{$attachment->business_id}/{$attachment->manual_journal_id}",
            $attachment->id.'_'.basename($attachment->file_name),
            'local',
        );
        $attachment->update(['file_path' => $path, 'size_bytes' => $data['file']->getSize()]);

        return response()->json(['file_path' => $path]);
    }
}
