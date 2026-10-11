<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceDbSnapshot;
use App\Services\DeviceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * On-demand copies of a device's local database, for debugging.
 *
 *  1. The developer device asks: POST /sync/diagnostics/db-snapshots
 *     {device: id | identifier | name}.
 *  2. The target device sees `db_snapshot_request` in the response to its
 *     next diagnostics report (SyncDiagnosticsController::store), takes a
 *     consistent VACUUM INTO copy, gzips it and uploads it in chunks small
 *     enough for any web server's body limit (…/{id}/chunks), then calls
 *     …/{id}/complete with the sha256.
 *  3. The developer device lists (GET …/db-snapshots) and downloads
 *     (GET …/db-snapshots/{id}/download) the .db.gz.
 *
 * Requesting, listing and downloading are reader-only (see
 * Device::isDiagnosticsReader); uploading is only for the device asked.
 * Snapshots are pruned after [KEEP_DAYS].
 */
class DeviceDbSnapshotController extends Controller
{
    private const KEEP_DAYS = 7;

    /** Requests nobody answered within this long are given up on. */
    private const OPEN_HOURS = 24;

    private const MAX_CHUNKS = 4000;

    public function __construct(private readonly DeviceResolver $deviceResolver) {}

    /** Developer device: ask a device for a copy of its database. */
    public function store(Request $request): JsonResponse
    {
        $reader = $this->reader($request);
        if (! $reader) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate(['device' => 'required|string|max:255']);
        $this->prune();

        $value = $data['device'];
        $matches = Device::where('tenant_id', $reader->tenant_id)
            ->get()
            ->filter(fn (Device $d) => (string) $d->id === $value
                || $d->device_identifier === $value
                || stripos($d->name, $value) !== false);
        if ($matches->count() !== 1) {
            return response()->json([
                'message' => $matches->isEmpty() ? 'No such device' : 'More than one device matches',
                'matches' => $matches->map(fn (Device $d) => ['id' => $d->id, 'name' => $d->name])->values(),
            ], 422);
        }
        $target = $matches->first();

        $snapshot = DeviceDbSnapshot::where('device_id', $target->id)
            ->whereIn('status', DeviceDbSnapshot::OPEN_STATUSES)
            ->orderByDesc('id')
            ->first()
            ?? DeviceDbSnapshot::create([
                'business_id' => $reader->tenant_id,
                'device_id' => $target->id,
                'requested_by_device_id' => $reader->id,
                'status' => DeviceDbSnapshot::STATUS_REQUESTED,
                'requested_at' => now(),
            ]);

        return response()->json($this->present($snapshot, $target), 201);
    }

    /** Developer device: every snapshot for the business, newest first. */
    public function index(Request $request): JsonResponse
    {
        $reader = $this->reader($request);
        if (! $reader) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $this->prune();

        $devices = Device::where('tenant_id', $reader->tenant_id)->get()->keyBy('id');

        return response()->json([
            'snapshots' => DeviceDbSnapshot::where('business_id', $reader->tenant_id)
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (DeviceDbSnapshot $s) => $this->present($s, $devices->get($s->device_id))),
        ]);
    }

    /** Developer device: the gzipped database file. */
    public function download(Request $request, int $id): Response
    {
        $reader = $this->reader($request);
        if (! $reader) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $snapshot = DeviceDbSnapshot::where('business_id', $reader->tenant_id)->find($id);
        if (! $snapshot || $snapshot->status !== DeviceDbSnapshot::STATUS_READY
            || ! $snapshot->path || ! Storage::disk('local')->exists($snapshot->path)) {
            return response()->json(['message' => 'Snapshot not available'], 404);
        }

        $device = Device::find($snapshot->device_id);
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $device?->name ?? 'device')
            ."-{$snapshot->id}.db.gz";

        return Storage::disk('local')->download($snapshot->path, $name, [
            'Content-Type' => 'application/gzip',
        ]);
    }

    /** Target device: one base64 chunk of the gzipped copy. */
    public function chunk(Request $request, int $id): JsonResponse
    {
        $snapshot = $this->ownOpenSnapshot($request, $id);
        if ($snapshot instanceof JsonResponse) {
            return $snapshot;
        }

        $data = $request->validate([
            'index' => 'required|integer|min:0',
            'total' => 'required|integer|min:1|max:'.self::MAX_CHUNKS,
            'data' => 'required|string',
        ]);
        if ($data['index'] >= $data['total']) {
            return response()->json(['message' => 'index out of range'], 422);
        }
        $bytes = base64_decode($data['data'], true);
        if ($bytes === false) {
            return response()->json(['message' => 'data is not base64'], 422);
        }

        // Re-sending a chunk (lost response, restarted upload) overwrites it.
        Storage::disk('local')->put($snapshot->directory().'/part-'.$data['index'], $bytes);
        $snapshot->update([
            'status' => DeviceDbSnapshot::STATUS_UPLOADING,
            'chunks_total' => $data['total'],
        ]);

        return response()->json(['received' => $data['index']]);
    }

    /**
     * Target device: all chunks sent — assemble and verify. A device that
     * could not take the copy sends `error` instead.
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        $snapshot = $this->ownOpenSnapshot($request, $id);
        if ($snapshot instanceof JsonResponse) {
            return $snapshot;
        }

        $data = $request->validate([
            'sha256' => 'nullable|string|size:64',
            'app_version' => 'nullable|string|max:64',
            'schema_version' => 'nullable|integer|min:0',
            'error' => 'nullable|string|max:5000',
        ]);

        $disk = Storage::disk('local');
        $dir = $snapshot->directory();

        if (! empty($data['error']) || empty($data['sha256'])) {
            $disk->deleteDirectory($dir);
            $snapshot->update([
                'status' => DeviceDbSnapshot::STATUS_FAILED,
                'error' => $data['error'] ?? 'No checksum sent',
                'app_version' => $data['app_version'] ?? null,
                'completed_at' => now(),
            ]);

            return response()->json(['status' => $snapshot->status]);
        }

        $total = (int) $snapshot->chunks_total;
        for ($i = 0; $i < $total; $i++) {
            if (! $disk->exists("$dir/part-$i")) {
                return response()->json(['message' => "chunk $i missing"], 422);
            }
        }

        $path = "$dir/snapshot.db.gz";
        $out = fopen($disk->path($path), 'wb');
        $hash = hash_init('sha256');
        for ($i = 0; $i < $total; $i++) {
            $part = $disk->get("$dir/part-$i");
            fwrite($out, $part);
            hash_update($hash, $part);
        }
        fclose($out);
        $sha = hash_final($hash);

        if (! hash_equals($sha, strtolower($data['sha256']))) {
            // Keep the parts: the device re-sends them all on its next
            // attempt and completes again.
            $disk->delete($path);

            return response()->json(['message' => 'checksum mismatch'], 422);
        }

        for ($i = 0; $i < $total; $i++) {
            $disk->delete("$dir/part-$i");
        }
        $snapshot->update([
            'status' => DeviceDbSnapshot::STATUS_READY,
            'path' => $path,
            'sha256' => $sha,
            'size_bytes' => $disk->size($path),
            'app_version' => $data['app_version'] ?? null,
            'schema_version' => $data['schema_version'] ?? null,
            'completed_at' => now(),
        ]);

        return response()->json(['status' => $snapshot->status]);
    }

    private function reader(Request $request): ?Device
    {
        $device = $this->deviceResolver->fromRequest($request);

        return $device?->isDiagnosticsReader() ? $device : null;
    }

    private function ownOpenSnapshot(Request $request, int $id): DeviceDbSnapshot|JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
        if (! $device || $device->is_revoked) {
            return response()->json(['message' => 'Unknown device'], 403);
        }
        $snapshot = DeviceDbSnapshot::where('device_id', $device->id)->find($id);
        if (! $snapshot) {
            return response()->json(['message' => 'No such request'], 404);
        }
        if (! $snapshot->isOpen()) {
            return response()->json(['message' => 'Request already closed'], 409);
        }

        return $snapshot;
    }

    /** Drops snapshots past [KEEP_DAYS] and gives up on stale requests. */
    private function prune(): void
    {
        $disk = Storage::disk('local');
        DeviceDbSnapshot::where('created_at', '<', now()->subDays(self::KEEP_DAYS))
            ->get()
            ->each(function (DeviceDbSnapshot $s) use ($disk) {
                $disk->deleteDirectory($s->directory());
                $s->delete();
            });

        DeviceDbSnapshot::whereIn('status', DeviceDbSnapshot::OPEN_STATUSES)
            ->where('requested_at', '<', now()->subHours(self::OPEN_HOURS))
            ->get()
            ->each(function (DeviceDbSnapshot $s) use ($disk) {
                $disk->deleteDirectory($s->directory());
                $s->update([
                    'status' => DeviceDbSnapshot::STATUS_FAILED,
                    'error' => 'Device did not answer within '.self::OPEN_HOURS.'h',
                ]);
            });
    }

    private function present(DeviceDbSnapshot $s, ?Device $device): array
    {
        return [
            'id' => $s->id,
            'device_id' => $s->device_id,
            'device_name' => $device?->name,
            'status' => $s->status,
            'chunks_total' => $s->chunks_total,
            'size_bytes' => $s->size_bytes,
            'sha256' => $s->sha256,
            'app_version' => $s->app_version,
            'schema_version' => $s->schema_version,
            'error' => $s->error,
            'requested_at' => $s->requested_at?->toIso8601String(),
            'completed_at' => $s->completed_at?->toIso8601String(),
        ];
    }
}
