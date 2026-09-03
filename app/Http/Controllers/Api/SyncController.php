<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\SyncConflict;
use App\Models\SyncCursor;
use App\Models\SyncRecord;
use App\Services\DeviceResolver;
use App\Services\SyncProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncController extends Controller
{
    public function __construct(private readonly DeviceResolver $deviceResolver) {}

    /** Flutter POS → Server: receive records pushed from device */
    public function push(Request $request, SyncProcessor $processor): JsonResponse
    {
        $data = $request->validate([
            'records' => 'required|array|max:50',
            'records.*.table' => 'required|string',
            // uuid is relaxed to string to support composite keys (e.g. "productId|taxRateId")
            // and numeric IDs (e.g. po_audit_logs). The processor handles each table's key format.
            'records.*.uuid' => 'required|string|max:255',
            'records.*.operation' => 'required|in:insert,update,upsert,delete',
            'records.*.payload' => 'nullable|array',
            'records.*.updated_at' => 'required|date',
        ]);

        $device = $this->resolveDevice($request);
        $accepted = [];
        $conflicts = [];
        $errors = [];

        foreach ($this->groupPushRecords($data['records']) as $groupRecords) {
            // Version-conflict detection runs first and is recorded independently
            // of the processing transaction below — a conflict row must survive
            // even if a later record in this same group fails to process. (A
            // conflict recorded *inside* that transaction would be rolled back
            // along with everything else if a sibling record threw, leaving the
            // response pointing at a conflict id that no longer exists.)
            $recordsToProcess = [];

            foreach ($groupRecords as $record) {
                // Normalize insert/update to upsert — the processor treats them identically
                if (in_array($record['operation'], ['insert', 'update'])) {
                    $record['operation'] = 'upsert';
                }

                $incomingUpdatedAt = $this->resolveIncomingUpdatedAt($record);

                $existing = SyncRecord::where('business_id', $device?->tenant_id)
                    ->where('table_name', $record['table'])
                    ->where('record_uuid', $record['uuid'])
                    ->latest('synced_at')
                    ->first();

                $existingVersion = $existing?->source_updated_at ?? $existing?->synced_at;
                if ($existingVersion && $existingVersion->gt($incomingUpdatedAt)) {
                    $conflict = $this->recordConflict(
                        $device,
                        $record,
                        'Newer version exists on server; manual review required.',
                        'version_conflict',
                        $existing?->payload
                    );

                    // Left pending for manual review via the conflicts/resolve
                    // endpoint — do not apply or accept the older record yet.
                    $conflicts[] = [
                        'id' => $conflict->id,
                        'table' => $record['table'],
                        'uuid' => $record['uuid'],
                    ];

                    continue;
                }

                $recordsToProcess[] = ['record' => $record, 'incomingUpdatedAt' => $incomingUpdatedAt];
            }

            if (empty($recordsToProcess)) {
                continue;
            }

            $acceptedInGroup = [];

            try {
                DB::transaction(function () use ($recordsToProcess, $device, $processor, &$acceptedInGroup) {
                    foreach ($recordsToProcess as $item) {
                        $record = $item['record'];
                        $incomingUpdatedAt = $item['incomingUpdatedAt'];

                        // Enrich payload with server-known context so old queued records
                        // (created before clients included all fields) still process correctly.
                        $enrichedPayload = array_merge([
                            'business_id' => $device?->tenant_id,
                        ], $record['payload'] ?? []);

                        // If the payload explicitly passes null for business_id but the device
                        // tenant_id is known, prefer the device's value (safety net).
                        if (empty($enrichedPayload['business_id']) && $device?->tenant_id) {
                            $enrichedPayload['business_id'] = $device->tenant_id;
                        }

                        $processor->process(
                            $record['table'],
                            $record['uuid'],
                            $record['operation'],
                            $enrichedPayload,
                            trusted: false
                        );

                        SyncRecord::create([
                            'business_id' => $device?->tenant_id,
                            'table_name' => $record['table'],
                            'record_uuid' => $record['uuid'],
                            'operation' => $record['operation'],
                            'payload' => $record['payload'] ?? [],
                            'source_updated_at' => $incomingUpdatedAt,
                            'synced_at' => now(),
                            'device_id' => $device?->id,
                        ]);

                        $acceptedInGroup[] = [
                            'table' => $record['table'],
                            'uuid' => $record['uuid'],
                        ];
                    }
                });

                $accepted = [...$accepted, ...$acceptedInGroup];
            } catch (\Throwable $e) {
                foreach ($recordsToProcess as $item) {
                    $conflict = $this->recordConflict(
                        $device,
                        $item['record'],
                        $e->getMessage(),
                        'processing_error'
                    );
                    $errors[] = [
                        'id' => $conflict->id,
                        'table' => $item['record']['table'],
                        'uuid' => $item['record']['uuid'],
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        }

        if ($device) {
            $device->update(['last_seen_at' => now()]);
        }

        return response()->json([
            'accepted' => $accepted,
            'conflicts' => $conflicts,
            'errors' => $errors,
        ]);
    }

    /** Server → Flutter POS: send changes since device's last pull */
    public function pull(Request $request): JsonResponse
    {
        $request->validate([
            'since' => 'nullable|date',
            'tables' => 'nullable|array',
            'tables.*' => 'string',
        ]);

        $device = $this->resolveDevice($request);
        $tables = $request->input('tables', []);
        $serverTime = now();

        $query = SyncRecord::query()
            ->where('business_id', $device?->tenant_id);

        if ($tables) {
            $query->whereIn('table_name', $tables);
        }

        if ($request->filled('since')) {
            $query->where('synced_at', '>', $request->input('since'));
        } elseif ($device) {
            $cursors = SyncCursor::where('device_id', $device->id)
                ->when($tables, fn ($q) => $q->whereIn('table_name', $tables))
                ->pluck('last_pulled_at', 'table_name');

            if ($cursors->isNotEmpty()) {
                $query->where('synced_at', '>', $cursors->min());
            }
        }

        // Exclude records that originated from this device to avoid echo
        if ($device) {
            $query->where(function ($q) use ($device) {
                $q->whereNull('device_id')->orWhere('device_id', '!=', $device->id);
            });
        }

        $limit = 500;
        $records = $query->orderBy('synced_at')->limit($limit)->get();

        if ($device && $records->isNotEmpty()) {
            foreach ($records->groupBy('table_name') as $table => $tableRecords) {
                SyncCursor::updateOrCreate(
                    ['device_id' => $device->id, 'table_name' => $table],
                    ['last_pulled_at' => $tableRecords->max('synced_at')]
                );
            }
        }

        return response()->json([
            'records' => $records,
            'has_more' => $records->count() === $limit,
            'server_time' => $serverTime->toIso8601String(),
        ]);
    }

    /** Returns pending sync counts and cursors for this device */
    public function status(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);

        $query = SyncRecord::query()
            ->where('business_id', $device?->tenant_id);

        if ($device) {
            $cursors = SyncCursor::where('device_id', $device->id)
                ->pluck('last_pulled_at', 'table_name');

            if ($cursors->isNotEmpty()) {
                $query->where('synced_at', '>', $cursors->min());
            }

            // Exclude own records (already have them)
            $query->where(function ($q) use ($device) {
                $q->whereNull('device_id')->orWhere('device_id', '!=', $device->id);
            });
        }

        $pendingPull = $query->count();

        $cursorMap = $device
            ? SyncCursor::where('device_id', $device->id)
                ->pluck('last_pulled_at', 'table_name')
            : collect();

        return response()->json([
            'pending_pull' => $pendingPull,
            'device_id' => $device?->id,
            'cursors' => $cursorMap,
        ]);
    }

    public function conflicts(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);
        $status = $request->query('status', 'pending');
        $limit = max(1, min(200, (int) $request->query('limit', 100)));

        $query = SyncConflict::query()
            ->when($device?->tenant_id, fn ($q, $tenantId) => $q->where('business_id', $tenantId))
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return response()->json([
            'conflicts' => $query->get(),
        ]);
    }

    public function resolveConflict(Request $request, int $id, SyncProcessor $processor): JsonResponse
    {
        $device = $this->resolveDevice($request);
        $resolvedBy = $request->user()?->id;
        $data = $request->validate([
            'action' => 'required|in:accept_server,retry_local,merged',
            'merged_payload' => 'nullable|array',
            'updated_at' => 'nullable|date',
        ]);

        $conflict = SyncConflict::query()
            ->where('id', $id)
            ->when($device?->tenant_id, fn ($q, $tenantId) => $q->where('business_id', $tenantId))
            ->firstOrFail();

        if ($conflict->status !== 'pending') {
            return response()->json([
                'message' => 'Conflict already resolved',
                'conflict' => $conflict,
            ]);
        }

        DB::transaction(function () use ($data, $conflict, $device, $processor, $resolvedBy) {
            $action = $data['action'];

            if ($action === 'retry_local') {
                $payload = $conflict->local_payload ?? [];

                // Enrich payload with server-known context
                $enrichedPayload = array_merge([
                    'business_id' => $device?->tenant_id,
                ], $payload);
                if (empty($enrichedPayload['business_id']) && $device?->tenant_id) {
                    $enrichedPayload['business_id'] = $device->tenant_id;
                }

                $processor->process($conflict->table_name, $conflict->record_uuid, 'upsert', $enrichedPayload, trusted: false);

                SyncRecord::create([
                    'business_id' => $device?->tenant_id,
                    'table_name' => $conflict->table_name,
                    'record_uuid' => $conflict->record_uuid,
                    'operation' => 'upsert',
                    'payload' => $payload,
                    'source_updated_at' => isset($data['updated_at'])
                        ? Carbon::parse($data['updated_at'])
                        : now(),
                    'synced_at' => now(),
                    'device_id' => $device?->id,
                ]);
            } elseif ($action === 'merged') {
                $payload = $data['merged_payload'] ?? [];

                // Enrich payload with server-known context
                $enrichedPayload = array_merge([
                    'business_id' => $device?->tenant_id,
                ], $payload);
                if (empty($enrichedPayload['business_id']) && $device?->tenant_id) {
                    $enrichedPayload['business_id'] = $device->tenant_id;
                }

                $processor->process($conflict->table_name, $conflict->record_uuid, 'upsert', $enrichedPayload, trusted: false);

                SyncRecord::create([
                    'business_id' => $device?->tenant_id,
                    'table_name' => $conflict->table_name,
                    'record_uuid' => $conflict->record_uuid,
                    'operation' => 'upsert',
                    'payload' => $payload,
                    'source_updated_at' => isset($data['updated_at'])
                        ? Carbon::parse($data['updated_at'])
                        : now(),
                    'synced_at' => now(),
                    'device_id' => $device?->id,
                ]);
            }

            $conflict->update([
                'status' => 'resolved',
                'resolved_by' => $resolvedBy,
                'resolution_action' => $action,
                'resolved_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Conflict resolved',
            'conflict' => $conflict->fresh(),
        ]);
    }

    private function resolveDevice(Request $request): ?Device
    {
        return $this->deviceResolver->fromRequest($request);
    }

    private function resolveIncomingUpdatedAt(array $record)
    {
        $payloadUpdatedAt = data_get($record, 'payload.updated_at');
        $raw = $payloadUpdatedAt ?: $record['updated_at'];

        return Carbon::parse($raw);
    }

    private function groupPushRecords(array $records): array
    {
        $groups = [];

        foreach ($records as $record) {
            $table = $record['table'] ?? '';
            $payload = $record['payload'] ?? [];
            $key = match ($table) {
                'transactions' => 'tx:'.$record['uuid'],
                'transaction_items', 'transaction_taxes', 'payments' => 'tx:'.($payload['transaction_id'] ?? $record['uuid']),
                default => $table.':'.$record['uuid'],
            };

            if (! isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $record;
        }

        return array_values($groups);
    }

    private function recordConflict(?Device $device, array $record, string $reason, string $type, ?array $serverPayload = null): SyncConflict
    {
        // Truncate reason to avoid DB truncation errors on TEXT or legacy VARCHAR columns.
        // The full error is still surfaced in the JSON response to the device.
        $truncatedReason = mb_substr($reason, 0, 60000);

        return SyncConflict::updateOrCreate(
            [
                'business_id' => $device?->tenant_id,
                'table_name' => $record['table'] ?? '',
                'record_uuid' => mb_substr($record['uuid'] ?? '', 0, 255),
                'status' => 'pending',
            ],
            [
                'device_id' => $device?->id,
                'reason' => $truncatedReason,
                'conflict_type' => $type,
                'local_payload' => $record['payload'] ?? [],
                'server_payload' => $serverPayload,
            ]
        );
    }
}
