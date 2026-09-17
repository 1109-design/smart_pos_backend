<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalSyncConflict;
use App\Services\DeviceResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Central visibility for LAN peer-sync conflicts (see Flutter's
 * local_sync_engine.dart / LocalSyncConflicts table). Those conflicts are
 * detected and resolved entirely on-device by whichever two tills happened
 * to be on the same LAN — this endpoint just gives a manager reviewing
 * `/sync/conflicts` a way to also see the ones that never touched Laravel
 * at resolution time.
 */
class LocalSyncConflictController extends Controller
{
    public function __construct(private readonly DeviceResolver $deviceResolver) {}

    /** Flutter POS → Server: report already-resolved LAN sync conflicts. */
    public function store(Request $request): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);

        $data = $request->validate([
            'conflicts' => 'required|array',
            'conflicts.*.table' => 'required|string',
            'conflicts.*.record_uuid' => 'required|string',
            'conflicts.*.reason' => 'nullable|string',
            'conflicts.*.local_payload' => 'nullable',
            'conflicts.*.incoming_payload' => 'nullable',
            'conflicts.*.peer_device_name' => 'nullable|string',
            'conflicts.*.status' => 'nullable|string',
            'conflicts.*.occurred_at' => 'required|date',
        ]);

        // business_id is always derived from the authenticated device's own
        // tenant, never trusted from the payload — matches SyncController's
        // push() handling.
        $businessId = $device?->tenant_id;

        $accepted = 0;

        foreach ($data['conflicts'] as $conflict) {
            // insert-and-catch rather than firstOrCreate: the dedup key
            // includes occurred_at, and a raw client-sent ISO8601 string
            // doesn't reliably string-match against how the datetime cast
            // round-trips it — safer to let the DB unique constraint be the
            // actual source of truth for "have we seen this exact conflict
            // from this device before" and treat a collision as an
            // idempotent no-op (same pattern as the journal-posting
            // idempotency backstop).
            try {
                DB::transaction(function () use ($conflict, $device, $businessId) {
                    LocalSyncConflict::create([
                        'business_id' => $businessId,
                        'device_id' => $device?->id,
                        'table_name' => $conflict['table'],
                        'record_uuid' => $conflict['record_uuid'],
                        'occurred_at' => $conflict['occurred_at'],
                        'reason' => $conflict['reason'] ?? null,
                        'local_payload' => $this->decodePayload($conflict['local_payload'] ?? null),
                        'incoming_payload' => $this->decodePayload($conflict['incoming_payload'] ?? null),
                        'peer_device_name' => $conflict['peer_device_name'] ?? null,
                        'status' => $conflict['status'] ?? 'pending',
                    ]);
                });
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }
                // Already reported by an earlier attempt — fine, that's the
                // whole point of the dedup key.
            }
            $accepted++;
        }

        return response()->json(['accepted' => $accepted]);
    }

    public function index(Request $request): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
        $limit = max(1, min(200, (int) $request->query('limit', 100)));

        $query = LocalSyncConflict::query()
            ->when($device?->tenant_id, fn ($q, $tenantId) => $q->where('business_id', $tenantId))
            ->orderByDesc('occurred_at')
            ->limit($limit);

        return response()->json([
            'local_conflicts' => $query->get(),
        ]);
    }

    /**
     * The Flutter side stores local/incoming payloads as already-JSON-encoded
     * strings (drift TextColumn). Decode them here so they're stored as real
     * JSON, not a doubly-encoded string, for anyone querying this table
     * directly. Falls back to storing the raw value if it isn't valid JSON.
     */
    private function decodePayload(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
