<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Device;
use App\Services\DeviceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The owner's audit trail (Flutter: core/audit/audit_log.dart and the
 * Audit Trail screen). Devices upload their entries; the app reads them
 * back filtered and paged. Who may open the screen is the app's call
 * (reportsAudit permission) — the server only stores and returns.
 *
 * Append-only: no endpoint edits or deletes an entry.
 */
class AuditController extends Controller
{
    private const KINDS = ['event', 'data_change'];

    public function __construct(private readonly DeviceResolver $deviceResolver) {}

    /** Device → server: store a batch of entries. Idempotent by entry id. */
    public function push(Request $request): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
        if (! $device) {
            return response()->json(['message' => 'Unknown device'], 403);
        }

        $request->validate([
            'entries' => 'required|array|max:500',
            'app_version' => 'nullable|string|max:64',
        ]);

        // Entries are checked one by one rather than with validate(): one
        // malformed row must not hold back every entry queued behind it.
        $entries = [];
        foreach ($request->input('entries') as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $id = $raw['id'] ?? null;
            if (! is_string($id) || ! Str::isUuid($id)) {
                continue;
            }
            $entries[] = $raw;
        }
        usort($entries, fn ($a, $b) => ((int) ($a['seq'] ?? 0)) <=> ((int) ($b['seq'] ?? 0)));

        $ids = array_map(fn ($e) => $e['id'], $entries);
        $now = now();

        DB::transaction(function () use ($device, $entries, $ids, $now) {
            $known = AuditEntry::whereIn('entry_id', $ids)->pluck('entry_id')->flip();

            // The device's most recently received number — not the highest,
            // so after a restart the count carries on from the new numbers.
            $lastSeq = AuditEntry::where('device_id', $device->id)
                ->where('kind', '!=', 'integrity')
                ->orderByDesc('id')
                ->value('seq');

            $rows = [];
            foreach ($entries as $e) {
                if ($known->has($e['id'])) {
                    continue;
                }
                $seq = isset($e['seq']) && is_numeric($e['seq']) ? (int) $e['seq'] : null;
                $occurredAt = $this->time($e['occurred_at'] ?? null) ?? $now->copy();

                if ($seq !== null && $lastSeq !== null) {
                    if ($seq > $lastSeq + 1) {
                        $rows[] = $this->integrityRow($device, $occurredAt, $now, 'entries_missing',
                            $this->missingSummary($device, $lastSeq + 1, $seq - 1),
                            ['from_seq' => $lastSeq + 1, 'to_seq' => $seq - 1]);
                    } elseif ($seq <= $lastSeq) {
                        $rows[] = $this->integrityRow($device, $occurredAt, $now, 'log_restarted',
                            "{$device->name} started numbering its log again at #{$seq} (last was #{$lastSeq}) — "
                            .'its database was replaced, restored from a backup, or erased.',
                            ['previous_seq' => $lastSeq, 'new_seq' => $seq]);
                    }
                }
                if ($seq !== null) {
                    $lastSeq = $seq;
                }

                $kind = in_array($e['kind'] ?? null, self::KINDS, true) ? $e['kind'] : 'event';
                $details = $e['details'] ?? null;
                $rows[] = [
                    'entry_id' => $e['id'],
                    'business_id' => $device->tenant_id,
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'seq' => $seq,
                    'occurred_at' => $occurredAt->format('Y-m-d H:i:s.v'),
                    'received_at' => $now->format('Y-m-d H:i:s.v'),
                    'kind' => $kind,
                    'action' => $this->str($e['action'] ?? null, 64) ?? 'unknown',
                    'category' => $this->str($e['category'] ?? null, 32) ?? 'other',
                    'important' => ($e['important'] ?? true) ? 1 : 0,
                    'table_name' => $this->str($e['table_name'] ?? null, 64),
                    'record_id' => $this->str($e['record_id'] ?? null, 191),
                    'summary' => $this->str($e['summary'] ?? null, 2000),
                    'details' => is_array($details) ? json_encode($details) : null,
                    'actor' => in_array($e['actor'] ?? null, ['user', 'system'], true) ? $e['actor'] : 'user',
                    'user_id' => $this->str($e['user_id'] ?? null, 64),
                    'user_name' => $this->str($e['user_name'] ?? null, 255),
                    'user_role' => $this->str($e['user_role'] ?? null, 64),
                    'location_id' => $this->str($e['location_id'] ?? null, 64),
                    'till_id' => $this->str($e['till_id'] ?? null, 64),
                    'app_version' => $this->str($e['app_version'] ?? null, 64),
                ];
            }

            foreach (array_chunk($rows, 100) as $chunk) {
                AuditEntry::insertOrIgnore($chunk);
            }
        });

        return response()->json(['accepted' => $ids]);
    }

    /**
     * The trail, newest first. Filters: from, to, user_id, device_id,
     * category, action (comma lists), kind, table_name, record_id, q (text),
     * important_only (default true). Paged with `cursor` from next_cursor.
     * meta=1 adds the devices and staff to filter by, and per-action counts
     * for the same period and people.
     */
    public function index(Request $request): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
        if (! $device) {
            return response()->json(['message' => 'Unknown device'], 403);
        }
        $businessId = $device->tenant_id;
        $limit = max(1, min(200, (int) $request->query('limit', 100)));

        $base = AuditEntry::query()->where('business_id', $businessId);
        if ($from = $this->time($request->query('from'))) {
            $base->where('occurred_at', '>=', $from->format('Y-m-d H:i:s.v'));
        }
        if ($to = $this->time($request->query('to'))) {
            $base->where('occurred_at', '<', $to->format('Y-m-d H:i:s.v'));
        }
        if ($userId = $request->query('user_id')) {
            $base->where('user_id', $userId);
        }
        if ($deviceId = $request->query('device_id')) {
            $base->where('device_id', (int) $deviceId);
        }

        $query = (clone $base);
        if ($categories = $this->csv($request->query('category'))) {
            // Integrity alerts belong to every view: they're about the
            // trail itself being incomplete.
            $query->where(fn ($q) => $q->whereIn('category', $categories)->orWhere('kind', 'integrity'));
        }
        if ($actions = $this->csv($request->query('action'))) {
            $query->whereIn('action', $actions);
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }
        if ($table = $request->query('table_name')) {
            $query->where('table_name', $table);
        }
        if ($recordId = $request->query('record_id')) {
            $query->where('record_id', $recordId);
        }
        if ($request->boolean('important_only', true)) {
            $query->where('important', true);
        }
        if ($text = trim((string) $request->query('q', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $text).'%';
            $query->where(fn ($q) => $q->where('summary', 'like', $like)
                ->orWhere('user_name', 'like', $like)
                ->orWhere('record_id', 'like', $like)
                ->orWhere('device_name', 'like', $like));
        }
        if ($cursor = $request->query('cursor')) {
            [$at, $id] = array_pad(explode('|', (string) $cursor, 2), 2, null);
            $at = $this->time($at);
            if ($at && is_numeric($id)) {
                $atStr = $at->format('Y-m-d H:i:s.v');
                $query->where(fn ($q) => $q->where('occurred_at', '<', $atStr)
                    ->orWhere(fn ($q2) => $q2->where('occurred_at', $atStr)->where('id', '<', (int) $id)));
            }
        }

        $rows = $query->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $entries = $rows->map(fn (AuditEntry $e) => $this->entryJson($e, $this->previousDetails($e)))->values();
        $last = $rows->last();

        $response = [
            'entries' => $entries,
            'next_cursor' => $hasMore && $last
                ? $last->occurred_at->format('Y-m-d\TH:i:s.v\Z').'|'.$last->id
                : null,
            'server_time' => now()->toIso8601String(),
        ];

        if ($request->boolean('meta')) {
            $response['devices'] = Device::where('tenant_id', $businessId)
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (Device $d) => ['id' => $d->id, 'name' => $d->name])->values();
            $response['users'] = AuditEntry::where('business_id', $businessId)
                ->whereNotNull('user_id')
                ->select('user_id', DB::raw('MAX(user_name) as user_name'), DB::raw('MAX(user_role) as user_role'))
                ->groupBy('user_id')->get()
                ->map(fn ($u) => ['user_id' => $u->user_id, 'user_name' => $u->user_name, 'user_role' => $u->user_role])
                ->sortBy('user_name')->values();
            $response['counts'] = (clone $base)
                ->where('important', true)
                ->whereNotIn('action', ['saved', 'deleted'])
                ->select('action', DB::raw('COUNT(*) as c'))
                ->groupBy('action')->pluck('c', 'action')
                ->map(fn ($c) => (int) $c);
        }

        return response()->json($response);
    }

    /** The same record's details as of its previous change, for a before/after view. */
    private function previousDetails(AuditEntry $e): ?array
    {
        if ($e->kind !== 'data_change' || ! $e->table_name || ! $e->record_id) {
            return null;
        }
        $at = $e->occurred_at->format('Y-m-d H:i:s.v');
        $prev = AuditEntry::where('business_id', $e->business_id)
            ->where('table_name', $e->table_name)
            ->where('record_id', $e->record_id)
            ->where('kind', 'data_change')
            ->where(fn ($q) => $q->where('occurred_at', '<', $at)
                ->orWhere(fn ($q2) => $q2->where('occurred_at', $at)->where('id', '<', $e->id)))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->first(['details', 'occurred_at', 'user_name']);

        return $prev ? [
            'details' => $prev->details,
            'occurred_at' => $prev->occurred_at->format('Y-m-d\TH:i:s.v\Z'),
            'user_name' => $prev->user_name,
        ] : null;
    }

    private function entryJson(AuditEntry $e, ?array $previous): array
    {
        return [
            'id' => $e->entry_id,
            'seq' => $e->seq,
            'occurred_at' => $e->occurred_at->format('Y-m-d\TH:i:s.v\Z'),
            'received_at' => $e->received_at?->format('Y-m-d\TH:i:s.v\Z'),
            'kind' => $e->kind,
            'action' => $e->action,
            'category' => $e->category,
            'important' => $e->important,
            'table_name' => $e->table_name,
            'record_id' => $e->record_id,
            'summary' => $e->summary,
            'details' => $e->details,
            'actor' => $e->actor,
            'user_id' => $e->user_id,
            'user_name' => $e->user_name,
            'user_role' => $e->user_role,
            'device_id' => $e->device_id,
            'device_name' => $e->device_name,
            'location_id' => $e->location_id,
            'till_id' => $e->till_id,
            'app_version' => $e->app_version,
            'previous' => $previous,
        ];
    }

    private function integrityRow(Device $device, Carbon $at, Carbon $now, string $action, string $summary, array $details): array
    {
        return [
            'entry_id' => (string) Str::uuid(),
            'business_id' => $device->tenant_id,
            'device_id' => $device->id,
            'device_name' => $device->name,
            'seq' => null,
            'occurred_at' => $at->format('Y-m-d H:i:s.v'),
            'received_at' => $now->format('Y-m-d H:i:s.v'),
            'kind' => 'integrity',
            'action' => $action,
            'category' => 'security',
            'important' => 1,
            'table_name' => null,
            'record_id' => null,
            'summary' => $summary,
            'details' => json_encode($details),
            'actor' => 'server',
            'user_id' => null,
            'user_name' => null,
            'user_role' => null,
            'location_id' => null,
            'till_id' => null,
            'app_version' => null,
        ];
    }

    private function missingSummary(Device $device, int $from, int $to): string
    {
        $count = $to - $from + 1;
        $range = $count === 1 ? "#{$from}" : "#{$from}–#{$to}";

        return "{$count} log ".($count === 1 ? 'entry' : 'entries')." from {$device->name} never arrived ({$range}) — "
            .'deleted on the device before they were uploaded?';
    }

    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function str(mixed $value, int $max): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        $s = (string) $value;

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** @return string[] */
    private function csv(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
