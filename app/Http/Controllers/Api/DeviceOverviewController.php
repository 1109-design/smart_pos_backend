<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use App\Models\Device;
use App\Models\DeviceSyncHealth;
use App\Models\DeviceSyncIssue;
use App\Models\Location;
use App\Services\DeviceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every device of the asking device's business — version, online state and
 * sync health — for the app's Devices screen. Read-only, built from what
 * devices already report: last_seen_at (bumped on every push and pull) and
 * the periodic health report (SyncDiagnosticsController::store). Unlike
 * GET /sync/diagnostics it's open to any device of the business and leaves
 * out issue payloads and stacks; the app limits the screen to owners.
 */
class DeviceOverviewController extends Controller
{
    public function __construct(private readonly DeviceResolver $deviceResolver) {}

    public function index(Request $request): JsonResponse
    {
        $me = $this->deviceResolver->fromRequest($request);
        if (! $me) {
            return response()->json(['message' => 'Unknown device'], 403);
        }

        $devices = Device::where('tenant_id', $me->tenant_id)->get();
        $health = DeviceSyncHealth::whereIn('device_id', $devices->pluck('id'))
            ->get()
            ->keyBy('device_id');
        $issues = DeviceSyncIssue::where('business_id', $me->tenant_id)
            ->whereNull('resolved_at')
            ->groupBy('device_id')
            ->selectRaw('device_id, COUNT(*) as issues, SUM(occurrences) as occurrences, MAX(last_seen_at) as latest')
            ->get()
            ->keyBy('device_id');

        $locationIds = $devices->pluck('location_id')
            ->merge($health->map(fn ($h) => $h->snapshot['active_location_id'] ?? null))
            ->filter()
            ->unique();
        $locations = Location::whereIn('id', $locationIds)->pluck('name', 'id');

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'app_releases' => (object) AppRelease::latestByPlatform(),
            'devices' => $devices->map(function (Device $d) use ($me, $health, $issues, $locations) {
                $h = $health->get($d->id);
                $snapshot = $h?->snapshot ?? [];
                $locationId = $snapshot['active_location_id'] ?? $d->location_id;

                $queue = ['pending' => 0, 'failing' => 0, 'gave_up' => 0, 'conflicts' => 0];
                $oldestPending = null;
                foreach (($snapshot['tables'] ?? []) as $table) {
                    foreach ($queue as $key => $sum) {
                        $queue[$key] = $sum + (int) ($table[$key] ?? 0);
                    }
                    $oldest = $table['oldest_pending_at'] ?? null;
                    if ($oldest && (! $oldestPending || $oldest < $oldestPending)) {
                        $oldestPending = $oldest;
                    }
                }

                $issue = $issues->get($d->id);

                return [
                    'id' => $d->id,
                    'name' => $d->name,
                    'device_identifier' => $d->device_identifier,
                    'is_this_device' => $d->id === $me->id,
                    'is_revoked' => (bool) $d->is_revoked,
                    'paired_at' => $d->created_at?->toIso8601String(),
                    'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                    'location_name' => $locationId ? $locations->get($locationId) : null,
                    'app_version' => $h?->app_version,
                    'platform' => $h?->platform,
                    'current_user' => $h?->current_user,
                    'clock_skew_seconds' => $h?->clock_skew_seconds,
                    'last_sync_at' => $h?->last_sync_at?->toIso8601String(),
                    'last_successful_sync_at' => $h?->last_successful_sync_at?->toIso8601String(),
                    'health_reported_at' => $h?->reported_at?->toIso8601String(),
                    'schema_version' => $snapshot['schema_version'] ?? null,
                    'queue' => $queue + ['oldest_pending_at' => $oldestPending],
                    'unreported_lan_conflicts' => (int) ($snapshot['unreported_lan_conflicts'] ?? 0),
                    'last_error_messages' => array_slice($snapshot['last_error_messages'] ?? [], 0, 5),
                    'open_issues' => (int) ($issue?->issues ?? 0),
                    'open_issue_occurrences' => (int) ($issue?->occurrences ?? 0),
                ];
            })->values(),
        ]);
    }
}
