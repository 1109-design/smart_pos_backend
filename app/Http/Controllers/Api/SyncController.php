<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\SyncConflict;
use App\Models\SyncCursor;
use App\Models\SyncRecord;
use App\Services\SyncProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    /** Flutter POS → Server: receive records pushed from device */
    public function push(Request $request, SyncProcessor $processor): JsonResponse
    {
        $data = $request->validate([
            'records' => 'required|array|max:50',
            'records.*.table' => 'required|string',
            'records.*.uuid' => 'required|uuid',
            'records.*.operation' => 'required|in:insert,update,upsert,delete',
            'records.*.payload' => 'nullable|array',
            'records.*.updated_at' => 'required|date',
        ]);

        $device = $this->resolveDevice($request);
        $accepted = [];
        $conflicts = [];
        $errors = [];

        foreach ($this->groupPushRecords($data['records']) as $groupRecords) {
            $acceptedInGroup = [];
            $conflictsInGroup = [];

            try {
                DB::transaction(function () use ($groupRecords, $device, $processor, &$acceptedInGroup, &$conflictsInGroup) {
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
                                'Newer version already exists on server',
                                'version_conflict',
                                $existing?->payload
                            );
                            $conflictsInGroup[] = [
                                'id' => $conflict->id,
                                'table' => $record['table'],
                                'uuid' => $record['uuid'],
                                'reason' => 'Newer version already exists on server',
                            ];

                            throw new \RuntimeException('Conflict: Newer version already exists on server');
                        }

                        $processor->process(
                            $record['table'],
                            $record['uuid'],
                            $record['operation'],
                            $record['payload'] ?? []
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
                $conflicts = [...$conflicts, ...$conflictsInGroup];
            } catch (\Throwable $e) {
                if (! empty($conflictsInGroup)) {
                    $conflicts = [...$conflicts, ...$conflictsInGroup];
                    continue;
                }

                foreach ($groupRecords as $record) {
                    $conflict = $this->recordConflict(
                        $device,
                        $record,
                        $e->getMessage(),
                        'processing_error'
                    );
                    $errors[] = [
                        'id' => $conflict->id,
                        'table' => $record['table'],
                        'uuid' => $record['uuid'],
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

        $records = $query->orderBy('synced_at')->limit(500)->get();

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
                $processor->process($conflict->table_name, $conflict->record_uuid, 'upsert', $payload);

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

            if ($action === 'merged') {
                $payload = $data['merged_payload'] ?? [];
                $processor->process($conflict->table_name, $conflict->record_uuid, 'upsert', $payload);

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
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        $tokenId = explode('|', $token)[0] ?? null;

        return Device::where('token_id', $tokenId)->first();
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
                'transaction_items', 'transaction_taxes', 'payments' =>
                    'tx:'.($payload['transaction_id'] ?? $record['uuid']),
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
        return SyncConflict::updateOrCreate(
            [
                'business_id' => $device?->tenant_id,
                'table_name' => $record['table'] ?? '',
                'record_uuid' => $record['uuid'] ?? '',
                'status' => 'pending',
            ],
            [
                'device_id' => $device?->id,
                'reason' => $reason,
                'conflict_type' => $type,
                'local_payload' => $record['payload'] ?? [],
                'server_payload' => $serverPayload,
            ]
        );
    }
}
