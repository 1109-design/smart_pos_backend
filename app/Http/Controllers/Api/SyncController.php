<?php

namespace App\Http\Controllers\Api;

use App\Events\SyncTablesChanged;
use App\Exceptions\MissingParentRecordException;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\PendingSyncRecord;
use App\Models\StockOversell;
use App\Models\SyncConflict;
use App\Models\SyncCursor;
use App\Models\SyncRecord;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AutoConflictResolver;
use App\Services\DeviceResolver;
use App\Services\SyncProcessor;
use App\Services\Zimra\ZimraSalesService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        $device = $this->deviceResolver->fromRequest($request);
        // The user the device's own bearer token authenticates as — needed
        // to authorize a 'users' role change (see SyncProcessor::syncUser())
        // against who is actually pushing it, not just what it claims.
        $actingUser = $request->user();
        $accepted = [];
        $conflicts = [];
        $autoResolved = [];
        $errors = [];
        $deferred = [];

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

                // Tombstone precedence: once a delete for this uuid has been
                // recorded, no later-arriving edit may resurrect it —
                // regardless of what timestamp the edit claims. Client clocks
                // aren't trusted for this: an offline device's edit can
                // easily carry a timestamp that *looks* newer than a delete
                // that actually happened after it on another device (see the
                // sync audit's clock-skew section). Deletes/voids are a
                // Category-C conflict — always routed to a human, never
                // silently applied OR silently discarded either direction.
                if ($existing?->operation === 'delete' && $record['operation'] !== 'delete') {
                    $conflict = $this->recordConflict(
                        $device,
                        $record,
                        'This record was deleted on another device; the incoming edit was not applied automatically and requires manual review.',
                        'edit_after_delete',
                        $existing->payload
                    );

                    $conflicts[] = [
                        'id' => $conflict->id,
                        'table' => $record['table'],
                        'uuid' => $record['uuid'],
                    ];

                    continue;
                }

                $existingVersion = $existing?->source_updated_at ?? $existing?->synced_at;
                if ($existingVersion && $existingVersion->gt($incomingUpdatedAt)) {
                    $merged = $this->attemptFieldLevelMerge($record, $existing);

                    if ($merged !== null) {
                        try {
                            DB::transaction(function () use ($record, $device, $actingUser, $processor, $merged, $incomingUpdatedAt) {
                                $enrichedMerge = array_merge([
                                    'business_id' => $device?->tenant_id,
                                ], $merged);
                                if ($device?->tenant_id) {
                                    $enrichedMerge['business_id'] = $device->tenant_id;
                                }

                                $processor->process($record['table'], $record['uuid'], 'upsert', $enrichedMerge, trusted: false, actingUser: $actingUser);

                                SyncRecord::create([
                                    'business_id' => $device?->tenant_id,
                                    'table_name' => $record['table'],
                                    'record_uuid' => $record['uuid'],
                                    'operation' => 'upsert',
                                    'payload' => $merged,
                                    'source_updated_at' => $incomingUpdatedAt,
                                    'synced_at' => now(),
                                    'device_id' => $device?->id,
                                ]);
                            });

                            $conflict = $this->recordConflict(
                                $device,
                                $record,
                                'Disjoint field-level changes merged automatically (fields: '.implode(', ', $record['payload']['_dirty_fields'] ?? []).' vs. '.implode(', ', $existing?->payload['_dirty_fields'] ?? []).').',
                                'version_conflict',
                                $existing?->payload
                            );
                            $conflict->update([
                                'status' => 'resolved',
                                'resolution_action' => 'merged',
                                'resolved_at' => now(),
                            ]);

                            $autoResolved[] = [
                                'id' => $conflict->id,
                                'table' => $record['table'],
                                'uuid' => $record['uuid'],
                            ];
                        } catch (\Throwable $e) {
                            $conflict = $this->recordConflict($device, $record, $e->getMessage(), 'processing_error');
                            $errors[] = [
                                'id' => $conflict->id,
                                'table' => $record['table'],
                                'uuid' => $record['uuid'],
                                'reason' => $e->getMessage(),
                            ];
                        }

                        continue;
                    }

                    $autoResolve = $this->autoResolvesVersionConflict($record['table']);
                    $ledgerSuperseded = ! $autoResolve && $this->isLedgerRecomputeSupersededConflict($record['table'], $existing);
                    // Rule A (identical content): the push carries no value the
                    // server doesn't already hold — same-value race, not a real
                    // divergence. Safe for every table since nothing changes.
                    // Checked first: it is the most precise reason when it hits.
                    $identicalContent = app(AutoConflictResolver::class)->payloadsMatch(
                        $record['payload'] ?? [],
                        $existing?->payload
                    );

                    $conflict = $this->recordConflict(
                        $device,
                        $record,
                        match (true) {
                            $identicalContent => 'Incoming values identical to the server record; auto-resolved by keeping the server record. No action needed.',
                            $ledgerSuperseded => 'Superseded by an authoritative stock_movements ledger recompute — the server quantity already reflects every device\'s sales/receipts, so this device\'s own stale snapshot was discarded automatically. No action needed.',
                            $autoResolve => 'Newer version exists on server; auto-resolved by keeping the server record.',
                            default => 'Newer version exists on server; manual review required.',
                        },
                        'version_conflict',
                        $existing?->payload
                    );

                    if ($autoResolve || $ledgerSuperseded || $identicalContent) {
                        // Last-write-wins: the server row is already newer than this
                        // push, so there's nothing to apply — just record the decision
                        // instead of leaving it pending for a human. Scoped to tables
                        // where a stale full-row overwrite is the only failure mode
                        // (see autoResolvesVersionConflict()); structural conflicts
                        // (invalid transitions, ownership mismatches) are never
                        // auto-resolved because they signal a real bug, not a race.
                        // The ledger-superseded case is narrower still — see
                        // isLedgerRecomputeSupersededConflict()'s own doc comment.
                        $conflict->update([
                            'status' => 'resolved',
                            'resolution_action' => $identicalContent
                                ? 'accept_server_identical'
                                : ($ledgerSuperseded ? 'ledger_recompute_authoritative' : 'accept_server'),
                            'resolved_at' => now(),
                        ]);

                        $autoResolved[] = [
                            'id' => $conflict->id,
                            'table' => $record['table'],
                            'uuid' => $record['uuid'],
                        ];
                    } else {
                        // Left pending for manual review via the conflicts/resolve
                        // endpoint — do not apply or accept the older record yet.
                        $conflicts[] = [
                            'id' => $conflict->id,
                            'table' => $record['table'],
                            'uuid' => $record['uuid'],
                        ];
                    }

                    continue;
                }

                $recordsToProcess[] = ['record' => $record, 'incomingUpdatedAt' => $incomingUpdatedAt];
            }

            if (empty($recordsToProcess)) {
                continue;
            }

            $acceptedInGroup = [];

            try {
                DB::transaction(function () use ($recordsToProcess, $device, $actingUser, $processor, &$acceptedInGroup) {
                    foreach ($recordsToProcess as $item) {
                        $record = $item['record'];
                        $incomingUpdatedAt = $item['incomingUpdatedAt'];

                        // Enrich payload with server-known context so old queued records
                        // (created before clients included all fields) still process correctly.
                        $enrichedPayload = array_merge([
                            'business_id' => $device?->tenant_id,
                        ], $record['payload'] ?? []);

                        // A device is authorized for exactly one tenant — it can never have
                        // a legitimate reason to write into another business_id, whether the
                        // target record already exists or not. assertOwnership() only
                        // catches a mismatch against an *existing* row's owner (this is what
                        // stops a device from hijacking another business's record by
                        // guessing its uuid); it has no way to know the true owner of a
                        // brand-new row, since there's nothing in the database yet to check
                        // against. Without this, a device could plant a fabricated row in
                        // any OTHER business's tenant scope simply by claiming that
                        // business_id on a uuid that doesn't exist yet. Forcing the device's
                        // own tenant_id here — unconditionally, not just when the payload
                        // omits one — closes that regardless of what the payload claims.
                        if ($device?->tenant_id) {
                            $enrichedPayload['business_id'] = $device->tenant_id;
                        }

                        $processor->process(
                            $record['table'],
                            $record['uuid'],
                            $record['operation'],
                            $enrichedPayload,
                            trusted: false,
                            actingUser: $actingUser,
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
            } catch (MissingParentRecordException $e) {
                // The parent this group depends on hasn't arrived on the
                // server yet — a genuine out-of-order push, not a rejection.
                // Queue the whole group (it was one atomic unit) rather than
                // failing it, so it can be replayed automatically the moment
                // its dependency lands (see resolvePendingRecords()), without
                // requiring the client to notice the failure and retry.
                foreach ($recordsToProcess as $item) {
                    $pending = PendingSyncRecord::create([
                        'business_id' => $device?->tenant_id,
                        'device_id' => $device?->id,
                        'acting_user_id' => $actingUser?->id,
                        'table_name' => $item['record']['table'],
                        'record_uuid' => $item['record']['uuid'],
                        'operation' => $item['record']['operation'],
                        'payload' => $item['record']['payload'] ?? [],
                        'source_updated_at' => $item['incomingUpdatedAt'],
                        'last_error' => $e->getMessage(),
                        'last_attempt_at' => now(),
                    ]);
                    $deferred[] = [
                        'id' => $pending->id,
                        'table' => $item['record']['table'],
                        'uuid' => $item['record']['uuid'],
                        'reason' => $e->getMessage(),
                    ];
                }
            } catch (\Throwable $e) {
                foreach ($recordsToProcess as $item) {
                    // Rule B (equivalent unique-key duplicate): the row the
                    // device tried to insert already exists with identical
                    // business values under a different UUID (every till
                    // seeding its own "piece" unit, etc.). Keeping the
                    // existing row loses nothing — verified unreferenced —
                    // so resolve immediately instead of parking it.
                    $autoDuplicate = $this->tryAutoResolveDuplicate(
                        $device,
                        $item['record'],
                        $e
                    );
                    if ($autoDuplicate !== null) {
                        $autoResolved[] = $autoDuplicate;

                        continue;
                    }
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

        // A dependency this business's pending records were waiting on may
        // have just landed above (in this very push, or an earlier one) —
        // give them a chance to apply now, so a device that later retries a
        // previously-failed push isn't the only path to convergence.
        $resolvedPending = $this->resolvePendingRecords($device?->tenant_id, $processor);

        // Realtime fan-out: every accepted table changed on the server in
        // this push — tell the business's other devices now instead of
        // making them wait for their next periodic poll. Payload carries
        // table names only; devices re-pull through the normal engine.
        // Skipped when nothing was accepted (no state changed) or the
        // tenant is unknown. Polling remains the fallback if the socket
        // is down — see RealtimeService's reconnect catch-up sync.
        $acceptedTables = collect($accepted)
            ->pluck('table')
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($acceptedTables !== [] && $device?->tenant_id) {
            SyncTablesChanged::dispatch($device->tenant_id, $acceptedTables);
        }

        return response()->json([
            'accepted' => $accepted,
            'conflicts' => $conflicts,
            'auto_resolved' => $autoResolved,
            'errors' => $errors,
            'deferred' => $deferred,
            'resolved_pending' => $resolvedPending,
        ]);
    }

    /**
     * Replays business's queued pending_sync_records (deferred because their
     * parent hadn't arrived yet) now that this push may have just created
     * one. Runs a fixed-point loop — not a single pass — so a chain (e.g. B
     * depends on A, C depends on B) resolves fully in one call once its root
     * dependency lands, rather than needing one push per link in the chain.
     *
     * @return array<int, array{id:int, table:string, uuid:string}>
     */
    private function resolvePendingRecords(?string $businessId, SyncProcessor $processor): array
    {
        if ($businessId === null) {
            return [];
        }

        $resolved = [];
        $maxAttempts = 10;

        do {
            $progressed = false;

            $pending = PendingSyncRecord::where('business_id', $businessId)
                ->where('failed_permanently', false)
                ->orderBy('id')
                ->get();

            foreach ($pending as $row) {
                $actingUser = $row->acting_user_id
                    ? User::find($row->acting_user_id)
                    : null;

                try {
                    DB::transaction(function () use ($row, $processor, $actingUser) {
                        $enrichedPayload = array_merge(['business_id' => $row->business_id], $row->payload ?? []);

                        $processor->process(
                            $row->table_name,
                            $row->record_uuid,
                            $row->operation,
                            $enrichedPayload,
                            trusted: false,
                            actingUser: $actingUser,
                        );

                        SyncRecord::create([
                            'business_id' => $row->business_id,
                            'table_name' => $row->table_name,
                            'record_uuid' => $row->record_uuid,
                            'operation' => $row->operation,
                            'payload' => $row->payload ?? [],
                            'source_updated_at' => $row->source_updated_at ?? now(),
                            'synced_at' => now(),
                            'device_id' => $row->device_id,
                        ]);
                    });

                    $resolved[] = [
                        'id' => $row->id,
                        'table' => $row->table_name,
                        'uuid' => $row->record_uuid,
                    ];
                    $row->delete();
                    $progressed = true;
                } catch (\Throwable $e) {
                    $attempts = $row->attempts + 1;
                    $row->update([
                        'attempts' => $attempts,
                        'last_error' => $e->getMessage(),
                        'last_attempt_at' => now(),
                        'failed_permanently' => $attempts >= $maxAttempts,
                    ]);

                    if ($attempts >= $maxAttempts) {
                        Log::warning('pending_sync_record permanently failed', [
                            'id' => $row->id,
                            'table' => $row->table_name,
                            'uuid' => $row->record_uuid,
                            'reason' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } while ($progressed);

        return $resolved;
    }

    /** Server → Flutter POS: send changes since device's last pull */
    public function pull(Request $request): JsonResponse
    {
        $request->validate([
            'since' => 'nullable|date',
            // Tie-breaker alongside `since` — see the migration adding
            // last_pulled_id for why synced_at alone silently drops rows.
            'after_id' => 'nullable|integer',
            'tables' => 'nullable|array',
            'tables.*' => 'string',
        ]);

        $device = $this->deviceResolver->fromRequest($request);
        $tables = $request->input('tables', []);
        $serverTime = now();

        $reconciliationRequired = $device
            ? $this->detectReconciliationRequired($request, $device, $tables)
            : [];

        $query = SyncRecord::query()
            ->where('business_id', $device?->tenant_id);

        if ($tables) {
            $query->whereIn('table_name', $tables);
        }

        // synced_at has only whole-second precision, so a busy table can
        // have hundreds of rows sharing one exact value — plain `> $since`
        // pagination means whichever tied rows land just past a page
        // boundary get permanently excluded once the cursor moves on to
        // that same synced_at. `after_id` (sync_records.id, a tie-free
        // autoincrement) breaks the tie: also accept anything sharing the
        // boundary synced_at with a strictly greater id.
        if ($request->filled('since')) {
            $sinceAt = $request->input('since');
            $afterId = $request->input('after_id');
            $query->where(function ($q) use ($sinceAt, $afterId) {
                $q->where('synced_at', '>', $sinceAt);
                if ($afterId !== null) {
                    $q->orWhere(function ($q2) use ($sinceAt, $afterId) {
                        $q2->where('synced_at', '=', $sinceAt)->where('id', '>', $afterId);
                    });
                }
            });
        } elseif ($device) {
            $cursorsByTable = SyncCursor::where('device_id', $device->id)
                ->when($tables, fn ($q) => $q->whereIn('table_name', $tables))
                ->get(['table_name', 'last_pulled_at', 'last_pulled_id'])
                ->keyBy('table_name');

            if ($cursorsByTable->isNotEmpty()) {
                // Per-table threshold, not a single global minimum — a
                // realtime quickPullTables() call routinely requests several
                // tables whose cursors have diverged (one advanced by
                // frequent activity, another stale), and a single `synced_at
                // > cursors->min()` bound made the whole query use the
                // oldest one, re-fetching records for the already-current
                // tables that were already pulled and applied. Idempotent
                // (not data corruption) but wasted bandwidth/processing on
                // every such call. A table with no cursor row yet (never
                // pulled before) gets no lower bound, so its full history
                // comes through on the first pull that asks for it.
                //
                // Each table's own threshold is applied tie-safely too, via
                // last_pulled_id — same reasoning as the `since`/`after_id`
                // branch above.
                $query->where(function ($q) use ($cursorsByTable) {
                    foreach ($cursorsByTable as $table => $cursor) {
                        $q->orWhere(function ($qq) use ($table, $cursor) {
                            $qq->where('table_name', $table)->where(function ($q2) use ($cursor) {
                                $q2->where('synced_at', '>', $cursor->last_pulled_at);
                                if ($cursor->last_pulled_id !== null) {
                                    $q2->orWhere(function ($q3) use ($cursor) {
                                        $q3->where('synced_at', '=', $cursor->last_pulled_at)
                                            ->where('id', '>', $cursor->last_pulled_id);
                                    });
                                }
                            });
                        });
                    }
                    $q->orWhereNotIn('table_name', $cursorsByTable->keys()->all());
                });
            }
        }

        // Exclude records that originated from this device to avoid echo
        if ($device) {
            $query->where(function ($q) use ($device) {
                $q->whereNull('device_id')->orWhere('device_id', '!=', $device->id);
            });
        }

        $limit = 500;
        $records = $query->orderBy('synced_at')->orderBy('id')->limit($limit)->get();

        if ($device && $records->isNotEmpty()) {
            foreach ($records->groupBy('table_name') as $table => $tableRecords) {
                // Records arrive pre-sorted by (synced_at, id) from the query
                // above, so the last one in each table's group is already
                // the true max by that compound order — no re-sort needed.
                $last = $tableRecords->last();
                SyncCursor::updateOrCreate(
                    ['device_id' => $device->id, 'table_name' => $table],
                    ['last_pulled_at' => $last->synced_at, 'last_pulled_id' => $last->id]
                );
            }
        }

        return response()->json([
            'records' => $this->sanitizeApprovalPayloadJson($records),
            'has_more' => $records->count() === $limit,
            'server_time' => $serverTime->toIso8601String(),
            // See detectReconciliationRequired() — non-empty means the
            // client's own claimed cursor for one of the requested tables
            // doesn't match anything this server ever actually delivered to
            // it. The client resets ONLY that table's local cursor and lets
            // the ordinary incremental pull mechanism re-fetch its full
            // history next cycle — never a wipe of the whole local database,
            // and the outbox (pending pushes) is untouched either way.
            'reconciliation_required' => $reconciliationRequired,
        ]);
    }

    /**
     * PHP has no distinct "empty map" type — json_decode('{}', true) and
     * json_decode('[]', true) both produce []. Eloquent's 'array' cast on
     * SyncRecord::payload uses exactly that decode, so an approval_requests
     * record whose nested payload_json started life as an empty JSON
     * *object* comes back out of the cast as an empty PHP array — and
     * re-encoding a PHP array (even one that started as an object) always
     * produces '[]', never '{}'. Every client-side reader of payload_json
     * (every Flutter Approvals screen) decodes it expecting a JSON object
     * and throws on an array — this is what actually reaches the wire, so
     * fixing it has to happen here, after casts have already run and
     * flattened the distinction, not further upstream (ApprovalService
     * still normalizes what it writes too, but that alone can't survive
     * this round-trip). Converts $records to a plain array and re-injects
     * a stdClass for the empty case, since json_encode(object) always
     * renders '{}' regardless of nesting — unlike an array, it can't be
     * collapsed back into a plain array by a later cast.
     *
     * Also heals any row already corrupted this way before this fix
     * existed, since it runs on every read, not just new writes.
     *
     * @param  Collection<int, SyncRecord>  $records
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeApprovalPayloadJson($records): array
    {
        $recordsArray = $records->toArray();

        foreach ($recordsArray as &$record) {
            if (($record['table_name'] ?? null) !== 'approval_requests') {
                continue;
            }

            $payload = $record['payload'] ?? null;
            if (is_array($payload) && ($payload['payload_json'] ?? null) === []) {
                $payload['payload_json'] = (object) [];
                $record['payload'] = $payload;
            }
        }
        unset($record);

        return $recordsArray;
    }

    /**
     * A device claiming a pull position (`since`/`after_id`) the server has
     * no record of ever having delivered to it (via this same device's own
     * `sync_cursors` row) means that table's local cursor is untrustworthy —
     * e.g. a local database restored from an unrelated backup/device, a
     * device_identifier collision, or local data tampering/corruption. A
     * device that is simply behind (the ordinary, common case) always
     * claims a position at or behind what the server recorded here, so this
     * never fires for normal lag — only for a cursor the server itself never
     * produced. Only evaluated when the client sent an explicit `since`
     * (a table with no local cursor yet sends none, and gets its full
     * history through the ordinary code path — never flagged, nothing to
     * reconcile).
     *
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    private function detectReconciliationRequired(Request $request, Device $device, array $tables): array
    {
        if (! $tables || ! $request->filled('since')) {
            return [];
        }

        $claimedSince = Carbon::parse($request->input('since'));
        $claimedAfterId = (int) $request->input('after_id', 0);

        $serverCursors = SyncCursor::where('device_id', $device->id)
            ->whereIn('table_name', $tables)
            ->get()
            ->keyBy('table_name');

        $flagged = [];
        foreach ($tables as $tableName) {
            $serverCursor = $serverCursors->get($tableName);

            if ($serverCursor === null) {
                // Device claims a pulled-up-to position for a table the
                // server has never recorded serving it anything for.
                $flagged[] = $tableName;

                continue;
            }

            $aheadOfServer = $claimedSince->gt($serverCursor->last_pulled_at)
                || ($claimedSince->eq($serverCursor->last_pulled_at)
                    && $claimedAfterId > (int) ($serverCursor->last_pulled_id ?? 0));

            if ($aheadOfServer) {
                $flagged[] = $tableName;
            }
        }

        return $flagged;
    }

    /** Returns pending sync counts and cursors for this device */
    public function status(Request $request): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);

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
            // Doubles as the closest thing to a SYNC_HELLO handshake
            // response today (see the startup-sync architecture spec):
            // server_time lets the client detect clock skew, the two
            // version fields let it detect an unsupported build/schema.
            // Deliberately additive to the existing pending_pull/cursors
            // shape used by the app's periodic "Ping Server" check, not a
            // new endpoint — SyncController already owns this concern.
            'server_time' => now()->toIso8601String(),
            'schema_version' => config('sync.schema_version'),
            'minimum_supported_app_version' => config('sync.minimum_supported_app_version'),
        ]);
    }

    /**
     * Unresolved stock shortfalls detected when a movement-ledger recompute
     * (SyncProcessor::recomputeProductStock/recomputeLocationStock) summed
     * below zero — i.e. two or more devices oversold the same product while
     * offline. Read-only visibility for now; resolving one (adjusting stock,
     * cancelling a sale, or accepting the negative) is a manual DB/BackOffice
     * action until a dedicated resolution workflow exists.
     */
    public function oversells(Request $request): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
        $status = $request->query('status', 'unresolved');
        $limit = max(1, min(200, (int) $request->query('limit', 100)));

        $query = StockOversell::query()
            ->when($device?->tenant_id, fn ($q, $tenantId) => $q->where('business_id', $tenantId))
            ->orderByDesc('detected_at')
            ->limit($limit);

        if ($status === 'unresolved') {
            $query->whereNull('resolved_at');
        } elseif ($status === 'resolved') {
            $query->whereNotNull('resolved_at');
        }

        return response()->json([
            'oversells' => $query->get(),
        ]);
    }

    public function conflicts(Request $request): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
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
        $device = $this->deviceResolver->fromRequest($request);
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

        $actingUser = $request->user();

        DB::transaction(function () use ($data, $conflict, $device, $actingUser, $processor, $resolvedBy) {
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

                $processor->process($conflict->table_name, $conflict->record_uuid, 'upsert', $enrichedPayload, trusted: false, actingUser: $actingUser);

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

                $processor->process($conflict->table_name, $conflict->record_uuid, 'upsert', $enrichedPayload, trusted: false, actingUser: $actingUser);

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

    /**
     * Tables whose version conflicts are safe to auto-resolve last-write-wins
     * (keep the server's newer record, drop the stale local push) instead of
     * waiting on manual review. Every 'products' upsert is a full-row replace
     * of simple scalar fields with no cross-record side effects, so an older
     * push losing to a newer server row is a plain race, not a data-loss risk.
     *
     * Deliberately excludes processing_error conflicts (invalid stock_take
     * transitions, ownership mismatches) — those always indicate a real bug
     * and must stay manual.
     *
     * @var list<string>
     */
    private const AUTO_RESOLVE_VERSION_CONFLICT_TABLES = ['products'];

    private function autoResolvesVersionConflict(string $table): bool
    {
        return in_array($table, self::AUTO_RESOLVE_VERSION_CONFLICT_TABLES, true);
    }

    /**
     * Tables whose unique-key duplicates may auto-resolve via Rule B, with
     * the `table.column` pairs that could reference the loser UUID. A table
     * joins this list only after verifying its would-be referrers don't use
     * the UUID (units_of_measure: products store the unit as plain text,
     * proven against the live database) — anything unlisted abstains and
     * stays manual.
     *
     * @var array<string, list<string>>
     */
    private const DUPLICATE_AUTO_RESOLVE_TABLES = [
        'units_of_measure' => [],
    ];

    /**
     * Rule B entry point for the push catch-all: returns the auto_resolved
     * entry when the 1062 duplicate is business-equivalent to the existing
     * row, else null (caller parks it as a manual processing_error).
     *
     * @param  array<string, mixed>  $record  Push record shape (table/uuid/operation/payload).
     * @return array{id:int, table:string, uuid:string}|null
     */
    private function tryAutoResolveDuplicate(
        ?Device $device,
        array $record,
        \Throwable $e
    ): ?array {
        $previous = $e;
        $duplicateMessage = null;
        do {
            // MySQL reports driver code 1062; SQLite reports 19 — match the
            // message shape instead so the rule works on both drivers.
            if ($previous instanceof QueryException
                && AutoConflictResolver::uniqueKeyColumns(
                    $record['table'] ?? '',
                    $previous->getMessage()
                ) !== null) {
                $duplicateMessage = $previous->getMessage();
                break;
            }
            $previous = $previous->getPrevious();
        } while ($previous !== null);

        if ($duplicateMessage === null) {
            return null;
        }

        $table = $record['table'] ?? null;
        if (! is_string($table) || ! array_key_exists($table, self::DUPLICATE_AUTO_RESOLVE_TABLES)) {
            return null;
        }

        $payload = array_merge(['business_id' => $device?->tenant_id], $record['payload'] ?? []);
        $existing = app(AutoConflictResolver::class)->findEquivalentRow(
            $table,
            $payload,
            $duplicateMessage,
            $record['uuid'] ?? '',
            self::DUPLICATE_AUTO_RESOLVE_TABLES[$table]
        );
        if ($existing === null) {
            return null;
        }

        $conflict = $this->recordConflict(
            $device,
            $record,
            "Duplicate '{$table}' entry matches the existing row's values; auto-resolved by keeping the existing row (equivalent UUID: ".($existing->id ?? $record['uuid'] ?? '?').'). No action needed.',
            'duplicate_superseded',
            (array) $existing
        );
        $conflict->update([
            'status' => 'resolved',
            'resolution_action' => 'accept_server_duplicate',
            'resolved_at' => now(),
        ]);

        return [
            'id' => $conflict->id,
            'table' => $table,
            'uuid' => $record['uuid'] ?? '',
        ];
    }

    /**
     * A narrower, structurally-provable auto-resolve than
     * AUTO_RESOLVE_VERSION_CONFLICT_TABLES: `product_stock`'s `quantity`
     * column is never the true source of truth — it's a cache recomputed
     * from the `stock_movements` ledger by
     * SyncProcessor::recomputeLocationStock() every time a movement lands
     * (see e.g. two devices independently selling the same product while
     * both offline, master spec section 9). That recompute always writes
     * its result via emitBroadcastSyncRecord() with device_id left null —
     * no device push ever does that — so `$existing->device_id === null`
     * is a precise signal that the server's current winning value already
     * IS the ledger-authoritative number, not just some other device's
     * unverified snapshot that happened to arrive first.
     *
     * A losing product_stock push in that situation is provably stale
     * (its own accompanying stock_movements row, processed moments later
     * in the very same request, is about to trigger the exact same
     * recompute again anyway), so leaving it "pending" only adds noise to
     * the manual conflict queue for something that was never actually in
     * dispute. Every other product_stock conflict — one device's genuine
     * edit racing another's (e.g. low_stock_threshold, price_override) —
     * still falls through to manual review untouched.
     */
    private function isLedgerRecomputeSupersededConflict(string $table, ?SyncRecord $existing): bool
    {
        return $table === 'product_stock' && $existing !== null && $existing->device_id === null;
    }

    /**
     * Tables where a version conflict MAY be auto-merged at the field level
     * instead of colliding as a whole-record conflict, when both the losing
     * push and the server's winning push can prove which fields they
     * actually intended to change (see attemptFieldLevelMerge()).
     *
     * Deliberately excludes every financial/stock/approval/void table per
     * the sync audit's conflict classification — those must never be
     * silently auto-merged even if the fields look disjoint.
     *
     * @var list<string>
     */
    private const FIELD_LEVEL_MERGE_TABLES = ['customers'];

    /**
     * Attempts a safe field-level merge of a losing (older) push against the
     * server's current winning version, returning the merged payload to
     * apply, or null if a merge cannot be safely proven.
     *
     * Why this needs an explicit `_dirty_fields` marker rather than diffing
     * the two payloads directly: every domain-table push from the Flutter
     * client today is a full-row snapshot built from whatever the device
     * last had cached locally (see e.g. customer_form.dart), not a partial
     * patch of only the fields the user actually edited. Two payloads can
     * therefore differ on a field neither side "intended" to change —
     * simply because one device's local cache was stale relative to the
     * other's — and a blind diff would misidentify that as a real edit,
     * either wrongly blocking a safe merge or, worse, wrongly applying one.
     * `_dirty_fields` is an optional array the client can send alongside the
     * full snapshot naming exactly which fields the user touched in this
     * edit; only when BOTH the incoming push and the server's currently
     * recorded winning push carry that marker do we have a real basis for
     * "these two edits touched disjoint fields." No current Flutter code
     * sends `_dirty_fields` yet, so this path is inert (always returns
     * null, falling through to today's whole-record conflict behavior)
     * until the client is updated to track and send it — see the sync
     * audit's gap report for the specific Flutter-side follow-up.
     *
     * @return array<string, mixed>|null
     */
    private function attemptFieldLevelMerge(array $record, ?SyncRecord $existing): ?array
    {
        if (! in_array($record['table'], self::FIELD_LEVEL_MERGE_TABLES, true)) {
            return null;
        }

        if ($existing === null) {
            return null;
        }

        $incomingDirty = $record['payload']['_dirty_fields'] ?? null;
        $serverDirty = $existing->payload['_dirty_fields'] ?? null;

        if (! is_array($incomingDirty) || empty($incomingDirty)
            || ! is_array($serverDirty) || empty($serverDirty)) {
            // Either side is a legacy/untracked full snapshot — we cannot
            // prove which fields it actually intended to change, so we
            // cannot safely tell "disjoint edit" apart from "stale copy".
            return null;
        }

        $incomingDirty = array_values(array_unique($incomingDirty));
        $serverDirty = array_values(array_unique($serverDirty));

        if (array_intersect($incomingDirty, $serverDirty) !== []) {
            // Same field changed on both sides — a real conflict, not a
            // safe merge. Fall through to the existing manual/auto-resolve
            // path unchanged.
            return null;
        }

        // Server's current payload is the base (it already reflects the
        // winning push's own dirty fields); overlay only the fields the
        // losing push explicitly marked as its own intentional edits.
        $merged = $existing->payload ?? [];
        foreach ($incomingDirty as $field) {
            if (array_key_exists($field, $record['payload'])) {
                $merged[$field] = $record['payload'][$field];
            }
        }

        // Union the dirty-field markers so a THIRD conflicting push can
        // still correctly tell which fields are already spoken for.
        $merged['_dirty_fields'] = array_values(array_unique([...$incomingDirty, ...$serverDirty]));

        return $merged;
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

    /**
     * Realtime acceleration endpoint: fiscalises a just-pushed transaction immediately
     * so the POS can print a complete fiscal receipt without waiting for background sync polling.
     */
    public function fiscalise(string $transactionId, Request $request, ZimraSalesService $zimraService): JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
        $transaction = Transaction::where('id', $transactionId)
            ->where('business_id', $device?->tenant_id)
            ->first();

        if (! $transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found on server',
            ], 404);
        }

        if ($transaction->fiscal_status === 'fiscalised') {
            return response()->json([
                'success' => true,
                'fiscalised' => true,
                'fiscal_status' => 'fiscalised',
                'fiscal_receipt_number' => $transaction->fiscal_receipt_number,
                'fiscal_qr_code' => $transaction->fiscal_qr_code,
            ]);
        }

        $result = $zimraService->processQueued($transaction);
        $transaction->refresh();

        return response()->json([
            'success' => $result['success'] ?? false,
            'fiscalised' => ($transaction->fiscal_status === 'fiscalised'),
            'fiscal_status' => $transaction->fiscal_status,
            'fiscal_receipt_number' => $transaction->fiscal_receipt_number,
            'fiscal_qr_code' => $transaction->fiscal_qr_code,
            'message' => $result['message'] ?? null,
        ]);
    }
}
