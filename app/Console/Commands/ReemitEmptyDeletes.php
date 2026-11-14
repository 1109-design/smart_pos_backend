<?php

namespace App\Console\Commands;

use App\Models\SyncRecord;
use Illuminate\Console\Command;

/**
 * Sends out again every delete that went to devices with an empty payload.
 *
 * Until SyncController::pull started sending an empty payload as `{}`, such
 * a delete went out as `[]`, which every app version failed to read and
 * skipped for good — so the deleted row stayed on every device (2026-10:
 * deleted approval rules and quotation lines). Pulled devices have long
 * moved past those records, so this writes a fresh copy of each delete
 * that every device pulls next. A delete whose record was written again
 * afterwards is left alone — re-sending it would wipe the newer row.
 * Re-applying a delete a device already has is harmless.
 */
class ReemitEmptyDeletes extends Command
{
    protected $signature = 'sync:reemit-empty-deletes
        {--since= : Only deletes synced on/after this date (default: everything)}
        {--dry-run : List what would be re-sent without writing anything}';

    protected $description = 'Re-send deletes that devices skipped because they went out with an empty payload';

    public function handle(): int
    {
        $deletes = SyncRecord::query()
            ->where('operation', 'delete')
            ->when($this->option('since'), fn ($q, $since) => $q->where('synced_at', '>=', $since))
            ->orderBy('id')
            ->get()
            ->filter(fn (SyncRecord $r) => empty($r->payload))
            ->unique(fn (SyncRecord $r) => $r->business_id.'|'.$r->table_name.'|'.$r->record_uuid);

        $resent = 0;
        foreach ($deletes as $delete) {
            $writtenAgain = SyncRecord::where('business_id', $delete->business_id)
                ->where('table_name', $delete->table_name)
                ->where('record_uuid', $delete->record_uuid)
                ->where('id', '>', $delete->id)
                ->where('operation', '!=', 'delete')
                ->exists();
            $label = "{$delete->table_name} {$delete->record_uuid} (deleted {$delete->synced_at})";
            if ($writtenAgain) {
                $this->line("skip  {$label}: written again since");

                continue;
            }

            $this->line(($this->option('dry-run') ? 'would ' : '')."re-send {$label}");
            if (! $this->option('dry-run')) {
                SyncRecord::create([
                    'business_id' => $delete->business_id,
                    'table_name' => $delete->table_name,
                    'record_uuid' => $delete->record_uuid,
                    'operation' => 'delete',
                    'payload' => ['deleted_at' => ($delete->synced_at ?? now())->toIso8601String()],
                    'source_updated_at' => $delete->source_updated_at ?? $delete->synced_at,
                    'synced_at' => now(),
                ]);
            }
            $resent++;
        }

        $this->info(($this->option('dry-run') ? 'Would re-send ' : 'Re-sent ')."{$resent} delete(s).");

        return self::SUCCESS;
    }
}
