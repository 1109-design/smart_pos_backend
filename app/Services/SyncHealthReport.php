<?php

namespace App\Services;

use App\Models\Device;
use App\Models\StockOversell;
use App\Models\SyncConflict;
use App\Models\SyncCursor;
use App\Models\SyncRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared read model behind `php artisan sync:health` and GET /api/v1/sync/health.
 *
 * "Pending" mirrors SyncController::status()'s own definition: SyncRecords
 * for this business, authored by another device, not yet older than this
 * device's oldest per-table cursor. "Failed" is deliberately NOT a true
 * count of failed push attempts — the server has no persisted log of
 * pushes that failed validation or never arrived (those live only in the
 * client's local SyncRecords.syncError, which never reaches the server).
 * It's approximated here as this device's share of unresolved sync
 * conflicts + unresolved stock oversells, i.e. "things that did reach the
 * server but are stuck" — documented explicitly so nobody mistakes it for
 * client-side failure visibility this endpoint cannot actually have.
 */
class SyncHealthReport
{
    /** @return array<int, array<string, mixed>> */
    public function devicesFor(string $tenantId): array
    {
        $devices = Device::where('tenant_id', $tenantId)->get();

        $unresolvedConflicts = SyncConflict::where('business_id', $tenantId)
            ->where('status', 'pending')
            ->get(['device_id']);

        $unresolvedOversellsCount = StockOversell::where('business_id', $tenantId)
            ->whereNull('resolved_at')
            ->count();

        return $devices->map(function (Device $device) use ($unresolvedConflicts, $unresolvedOversellsCount) {
            $cursors = SyncCursor::where('device_id', $device->id)->get();

            $pending = 0;
            if ($cursors->isNotEmpty()) {
                $oldestCursor = $cursors->min('last_pulled_at');
                $pending = SyncRecord::where('business_id', $device->tenant_id)
                    ->where(function ($q) use ($device) {
                        $q->whereNull('device_id')->orWhere('device_id', '!=', $device->id);
                    })
                    ->when($oldestCursor, fn ($q) => $q->where('synced_at', '>', $oldestCursor))
                    ->count();
            } else {
                // Never pulled anything — every non-own record is pending.
                $pending = SyncRecord::where('business_id', $device->tenant_id)
                    ->where(function ($q) use ($device) {
                        $q->whereNull('device_id')->orWhere('device_id', '!=', $device->id);
                    })
                    ->count();
            }

            $conflictsForDevice = $unresolvedConflicts->where('device_id', $device->id)->count();

            $lastSync = $cursors->max('last_pulled_at');

            return [
                'device_id' => $device->id,
                'device_name' => $device->name,
                'pending' => $pending,
                'failed' => $conflictsForDevice,
                'unresolved_oversells_business_wide' => $unresolvedOversellsCount,
                'last_sync' => $lastSync?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * Rough integrity signal, not a full audit: replays each table's
     * SyncRecord changelog to derive the "should exist" row count (latest
     * operation per uuid, excluding deletes) and compares it against the
     * live domain table's row count. A mismatch means either an
     * unprocessed delete, a row created outside the sync pipeline, or a
     * genuine data-integrity problem worth a human look — this only flags
     * it, it doesn't diagnose which.
     *
     * @return array<int, array<string, mixed>>
     */
    public function integrityFor(string $tenantId): array
    {
        $tables = ['transactions', 'payments'];
        $results = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $records = SyncRecord::where('business_id', $tenantId)
                ->where('table_name', $table)
                ->orderBy('record_uuid')
                ->orderBy('synced_at')
                ->orderBy('id')
                ->get(['record_uuid', 'operation']);

            $latestOperationByUuid = [];
            foreach ($records as $record) {
                // Records are already ordered oldest→newest per uuid, so the
                // last write for each uuid simply overwrites the previous.
                $latestOperationByUuid[$record->record_uuid] = $record->operation;
            }

            $expectedLiveCount = count(array_filter(
                $latestOperationByUuid,
                fn ($op) => $op !== 'delete'
            ));

            $actualLiveCount = Schema::hasColumn($table, 'business_id')
                ? DB::table($table)->where('business_id', $tenantId)->count()
                : null;

            $results[] = [
                'table' => $table,
                'expected_from_changelog' => $expectedLiveCount,
                'actual_live_rows' => $actualLiveCount,
                'mismatch' => $actualLiveCount !== null && $actualLiveCount !== $expectedLiveCount,
            ];
        }

        $pendingSyncTable = 'pending_sync_records';
        if (Schema::hasTable($pendingSyncTable) && Schema::hasColumn($pendingSyncTable, 'business_id')) {
            $results[] = [
                'table' => $pendingSyncTable,
                'deferred_count' => DB::table($pendingSyncTable)
                    ->where('business_id', $tenantId)->count(),
            ];
        }

        return $results;
    }

    /**
     * Products whose cross-location total (products.stock_quantity) no
     * longer equals the sum of their per-location stock (product_stock).
     * Both are recomputed from the same stock_movements ledger, so a gap
     * means some movement counts toward the total but no location — e.g.
     * an untagged opening_stock line that staff then re-entered at the
     * location as a manual adjustment (HIN007, 2026-10-05: 94 vs 46).
     * Only flags it; which line is the duplicate needs a human look.
     *
     * @return array<int, array{product_id: string, name: string, total: float, locations_sum: float, untagged_movements: float}>
     */
    public function stockMismatchesFor(string $tenantId): array
    {
        $locationSums = DB::table('product_stock')
            ->selectRaw('product_id, SUM(quantity) as locations_sum')
            ->groupBy('product_id');

        $untagged = DB::table('stock_movements')
            ->selectRaw('product_id, SUM(quantity_change) as untagged')
            ->whereNull('location_id')
            ->groupBy('product_id');

        return DB::table('products')
            ->joinSub($locationSums, 'ls', 'ls.product_id', '=', 'products.id')
            ->leftJoinSub($untagged, 'um', 'um.product_id', '=', 'products.id')
            ->where('products.business_id', $tenantId)
            ->where('products.track_stock', true)
            ->whereRaw('ABS(products.stock_quantity - ls.locations_sum) > 0.0001')
            ->orderBy('products.name')
            ->get(['products.id', 'products.name', 'products.stock_quantity', 'ls.locations_sum', 'um.untagged'])
            ->map(fn ($row) => [
                'product_id' => $row->id,
                'name' => $row->name,
                'total' => (float) $row->stock_quantity,
                'locations_sum' => (float) $row->locations_sum,
                'untagged_movements' => (float) ($row->untagged ?? 0),
            ])
            ->all();
    }
}
