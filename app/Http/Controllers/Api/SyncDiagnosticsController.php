<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceSyncHealth;
use App\Models\DeviceSyncIssue;
use App\Models\PendingSyncRecord;
use App\Models\SyncConflict;
use App\Services\DeviceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-device sync diagnostics. Every device reports its own sync problems
 * and a health snapshot (Flutter: core/sync/sync_issue_reporter.dart);
 * only the developer device(s) in config('sync.diagnostics_reader_devices')
 * can read them back, together with what the server itself knows (pending
 * conflicts, deferred records, live row counts per table).
 *
 * Diagnostic-only: nothing here feeds back into sync decisions.
 */
class SyncDiagnosticsController extends Controller
{
    public function __construct(private readonly DeviceResolver $deviceResolver) {}

    /** Device → server: upsert this device's issues and health snapshot. */
    public function store(Request $request): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
        if (! $device) {
            return response()->json(['message' => 'Unknown device'], 403);
        }

        $data = $request->validate([
            'device' => 'nullable|array',
            'device.app_version' => 'nullable|string|max:64',
            'device.platform' => 'nullable|string|max:255',
            'device.current_user' => 'nullable|string|max:255',
            'device.device_time' => 'nullable|date',
            'device.last_sync_at' => 'nullable|date',
            'device.last_successful_sync_at' => 'nullable|date',
            'health' => 'nullable|array',
            'issues' => 'nullable|array|max:200',
            'issues.*.fingerprint' => 'required|string|max:64',
            'issues.*.source' => 'required|string|max:32',
            'issues.*.category' => 'required|string|max:64',
            'issues.*.table' => 'nullable|string|max:255',
            'issues.*.message' => 'required|string',
            'issues.*.record_uuids' => 'nullable|array',
            'issues.*.record_uuid_count' => 'nullable|integer|min:0',
            'issues.*.sample_payload' => 'nullable',
            'issues.*.details' => 'nullable|array',
            'issues.*.stack' => 'nullable|string',
            'issues.*.occurrences' => 'nullable|integer|min:1',
            'issues.*.first_seen_at' => 'nullable|date',
            'issues.*.last_seen_at' => 'nullable|date',
            'issues.*.app_version' => 'nullable|string|max:64',
        ]);

        $now = now();
        $deviceInfo = $data['device'] ?? [];

        if (isset($data['health']) || $deviceInfo !== []) {
            $deviceTime = isset($deviceInfo['device_time'])
                ? Carbon::parse($deviceInfo['device_time'])
                : null;

            DeviceSyncHealth::updateOrCreate(
                ['device_id' => $device->id],
                [
                    'business_id' => $device->tenant_id,
                    'app_version' => $deviceInfo['app_version'] ?? null,
                    'platform' => $deviceInfo['platform'] ?? null,
                    'current_user' => $deviceInfo['current_user'] ?? null,
                    'device_time' => $deviceTime,
                    'clock_skew_seconds' => $deviceTime
                        ? (int) round($deviceTime->getTimestamp() - $now->getTimestamp())
                        : null,
                    'last_sync_at' => $deviceInfo['last_sync_at'] ?? null,
                    'last_successful_sync_at' => $deviceInfo['last_successful_sync_at'] ?? null,
                    'snapshot' => $data['health'] ?? null,
                    'reported_at' => $now,
                ]
            );
        }

        $accepted = [];
        foreach ($data['issues'] ?? [] as $issue) {
            $this->upsertIssue($device, $issue);
            $accepted[] = $issue['fingerprint'];
        }

        $device->update(['last_seen_at' => $now]);

        return response()->json(['accepted' => $accepted]);
    }

    /** Developer device only: every device's issues + health + server view. */
    public function index(Request $request): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
        if (! $this->isReader($device)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $tenantId = $device->tenant_id;
        $limit = max(1, min(2000, (int) $request->query('limit', 500)));
        $brief = $request->boolean('brief');
        $includeResolved = $request->boolean('include_resolved');

        $devices = Device::where('tenant_id', $tenantId)->get()->keyBy('id');
        $health = DeviceSyncHealth::whereIn('device_id', $devices->keys())->get()->keyBy('device_id');

        $issueQuery = DeviceSyncIssue::where('business_id', $tenantId)
            ->when(! $includeResolved, fn ($q) => $q->whereNull('resolved_at'))
            ->when($request->query('device'), function ($q, $value) use ($devices) {
                $ids = $devices->filter(fn (Device $d) => (string) $d->id === $value
                    || $d->device_identifier === $value
                    || stripos($d->name, $value) !== false)->keys();
                $q->whereIn('device_id', $ids);
            })
            ->when($request->query('table'), fn ($q, $v) => $q->where('table_name', $v))
            ->when($request->query('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->query('category'), fn ($q, $v) => $q->where('category', $v))
            ->when($request->query('since'), fn ($q, $v) => $q->where('last_seen_at', '>=', Carbon::parse($v)))
            ->orderByDesc('last_seen_at')
            ->limit($limit);

        $issues = $issueQuery->get()->map(function (DeviceSyncIssue $issue) use ($devices, $brief) {
            $row = $issue->toArray();
            $row['device_name'] = $devices->get($issue->device_id)?->name;
            if ($brief) {
                unset($row['sample_payload'], $row['stack'], $row['details']);
            }

            return $row;
        });

        $openCounts = DeviceSyncIssue::where('business_id', $tenantId)
            ->whereNull('resolved_at')
            ->groupBy('device_id')
            ->selectRaw('device_id, COUNT(*) as issues, SUM(occurrences) as occurrences')
            ->get()
            ->keyBy('device_id');

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'business_id' => $tenantId,
            'devices' => $devices->values()->map(fn (Device $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'device_identifier' => $d->device_identifier,
                'is_revoked' => (bool) $d->is_revoked,
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'open_issues' => (int) ($openCounts->get($d->id)?->issues ?? 0),
                'open_issue_occurrences' => (int) ($openCounts->get($d->id)?->occurrences ?? 0),
                'health' => $health->get($d->id)?->toArray(),
            ]),
            'issues' => $issues,
            'server' => $this->serverView($tenantId, $devices, $brief),
        ]);
    }

    /** Developer device only: mark issues resolved (they reopen if they recur). */
    public function resolve(Request $request): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
        if (! $this->isReader($device)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'fingerprints' => 'nullable|array',
            'fingerprints.*' => 'string',
            'note' => 'nullable|string',
        ]);

        $updated = DeviceSyncIssue::where('business_id', $device->tenant_id)
            ->where(function ($q) use ($data) {
                $q->whereIn('id', $data['ids'] ?? [])
                    ->orWhereIn('fingerprint', $data['fingerprints'] ?? []);
            })
            ->update([
                'resolved_at' => now(),
                'resolution_note' => $data['note'] ?? null,
            ]);

        return response()->json(['resolved' => $updated]);
    }

    private function normalizeReason(?string $reason): string
    {
        $reason = (string) $reason;
        $reason = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '<uuid>', $reason);

        return preg_replace('/\d+(\.\d+)?/', '<n>', $reason);
    }

    private function isReader(?Device $device): bool
    {
        return $device !== null
            && ! $device->is_revoked
            && in_array($device->device_identifier, config('sync.diagnostics_reader_devices', []), true);
    }

    private function upsertIssue(Device $device, array $issue): void
    {
        $lastSeen = isset($issue['last_seen_at']) ? Carbon::parse($issue['last_seen_at']) : now();

        $values = [
            'business_id' => $device->tenant_id,
            'source' => $issue['source'],
            'category' => $issue['category'],
            'table_name' => $issue['table'] ?? null,
            'message' => $issue['message'],
            'record_uuids' => $issue['record_uuids'] ?? null,
            'record_uuid_count' => $issue['record_uuid_count'] ?? count($issue['record_uuids'] ?? []),
            'sample_payload' => $issue['sample_payload'] ?? null,
            'details' => $issue['details'] ?? null,
            'stack' => $issue['stack'] ?? null,
            'last_seen_at' => $lastSeen,
            'app_version' => $issue['app_version'] ?? null,
        ];

        DB::transaction(function () use ($device, $issue, $values, $lastSeen) {
            $existing = DeviceSyncIssue::where('device_id', $device->id)
                ->where('fingerprint', $issue['fingerprint'])
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                DeviceSyncIssue::create($values + [
                    'device_id' => $device->id,
                    'fingerprint' => $issue['fingerprint'],
                    'occurrences' => $issue['occurrences'] ?? 1,
                    'first_seen_at' => $issue['first_seen_at'] ?? $lastSeen,
                ]);

                return;
            }

            // The device sends its cumulative count, so a resent report is
            // idempotent. A resolved issue that happened again reopens.
            $reopen = $existing->resolved_at !== null && $lastSeen->gt($existing->resolved_at);

            $existing->update($values + [
                'occurrences' => max($existing->occurrences, $issue['occurrences'] ?? 1),
                'resolved_at' => $reopen ? null : $existing->resolved_at,
            ]);
        });
    }

    /** What the server itself knows about this business's sync state. */
    private function serverView(string $tenantId, $devices, bool $brief): array
    {
        $deviceName = fn ($id) => $devices->get($id)?->name;

        $pendingConflicts = SyncConflict::where('business_id', $tenantId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (SyncConflict $c) use ($deviceName, $brief) {
                $row = $c->toArray();
                $row['device_name'] = $deviceName($c->device_id);
                if ($brief) {
                    unset($row['local_payload'], $row['server_payload']);
                }

                return $row;
            });

        // Every server-side conflict of the last 30 days (pending AND
        // resolved), grouped by device + table + reason with ids/uuids
        // stripped, so "234 x general_ledger: referenced journal_header_id
        // does not exist" reads as one line with sample record uuids.
        $conflictSummary = SyncConflict::where('business_id', $tenantId)
            ->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('id')
            ->get(['id', 'device_id', 'table_name', 'record_uuid', 'conflict_type', 'reason', 'status', 'resolution_action', 'resolved_by', 'created_at', 'resolved_at'])
            ->groupBy(fn (SyncConflict $c) => implode('|', [
                $c->device_id,
                $c->table_name,
                $c->conflict_type,
                $c->status,
                $c->resolution_action,
                $this->normalizeReason($c->reason),
            ]))
            ->map(function ($group) use ($deviceName) {
                $first = $group->first();

                return [
                    'device_id' => $first->device_id,
                    'device_name' => $deviceName($first->device_id),
                    'table_name' => $first->table_name,
                    'conflict_type' => $first->conflict_type,
                    'status' => $first->status,
                    'resolution_action' => $first->resolution_action,
                    'reason' => $this->normalizeReason($first->reason),
                    'example_reason' => $first->reason,
                    'total' => $group->count(),
                    'first_at' => $group->min('created_at')?->toIso8601String(),
                    'latest_at' => $group->max('created_at')?->toIso8601String(),
                    'latest_resolved_at' => $group->max('resolved_at')?->toIso8601String(),
                    'resolved_by' => $group->pluck('resolved_by')->filter()->unique()->values(),
                    'sample_record_uuids' => $group->pluck('record_uuid')->take(20)->values(),
                ];
            })
            ->sortByDesc('total')
            ->values();

        $deferred = PendingSyncRecord::where('business_id', $tenantId)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (PendingSyncRecord $p) use ($deviceName, $brief) {
                $row = $p->toArray();
                $row['device_name'] = $deviceName($p->device_id);
                if ($brief) {
                    unset($row['payload']);
                }

                return $row;
            });

        // Live uuids per table from the changelog: latest operation per
        // (table, uuid), excluding deletes. Compare with each device's
        // health.snapshot.tables[*].local_rows.
        $tableCounts = DB::table('sync_records as s')
            ->joinSub(
                DB::table('sync_records')
                    ->where('business_id', $tenantId)
                    ->groupBy('table_name', 'record_uuid')
                    ->selectRaw('MAX(id) as id'),
                'latest',
                'latest.id',
                '=',
                's.id'
            )
            ->where('s.operation', '!=', 'delete')
            ->groupBy('s.table_name')
            ->selectRaw('s.table_name, COUNT(*) as live_uuids')
            ->pluck('live_uuids', 'table_name');

        return [
            'pending_conflicts' => $pendingConflicts,
            'conflict_summary_30d' => $conflictSummary,
            'deferred_records' => $deferred,
            'changelog_live_uuids_per_table' => $tableCounts,
        ];
    }
}
