<?php

namespace App\Console\Commands;

use App\Models\Accounting\JournalHeader;
use App\Models\PendingSyncRecord;
use App\Models\SyncConflict;
use Illuminate\Console\Command;

/**
 * Clears journal lines stuck in the deferred queue because their journal
 * will never arrive: the server refused that journal as a duplicate of a
 * sale journal it already holds ("a journal already exists for sale:…"),
 * and the till has since removed its duplicate (SaleCogsRepairService). The
 * lines kept waiting for a parent that is gone for good (2026-10-05, till
 * 2CE9BA: 57 refused journals left 200 lines). The real sale journals are
 * on the server, so nothing is lost.
 *
 * Only discards a line whose journal is (a) absent from journal_headers and
 * (b) on record as refused as a duplicate. Anything else is listed, kept.
 */
class DiscardOrphanJournalLines extends Command
{
    protected $signature = 'sync:discard-orphan-journal-lines
        {--dry-run : List what would be discarded without deleting anything}';

    protected $description = 'Discard deferred journal lines whose journal was refused as a duplicate';

    public function handle(): int
    {
        $stuck = PendingSyncRecord::where('table_name', 'journal_lines')
            ->where('failed_permanently', true)
            ->get();

        $headerIds = $stuck->map(fn ($r) => $r->payload['journal_header_id'] ?? null)
            ->filter()
            ->unique()
            ->values();
        $existing = JournalHeader::whereIn('id', $headerIds)->pluck('id')->flip();
        $refusedAsDuplicate = SyncConflict::where('table_name', 'journal_headers')
            ->whereIn('record_uuid', $headerIds)
            ->where('reason', 'like', '%already exists for sale%')
            ->pluck('record_uuid')
            ->flip();

        $discard = $stuck->filter(function ($r) use ($existing, $refusedAsDuplicate) {
            $header = $r->payload['journal_header_id'] ?? null;

            return $header && ! $existing->has($header) && $refusedAsDuplicate->has($header);
        });
        $kept = $stuck->diff($discard);

        foreach ($kept as $r) {
            $this->line("keep  {$r->record_uuid} (journal ".($r->payload['journal_header_id'] ?? '?').' not a refused duplicate)');
        }

        $this->info(($this->option('dry-run') ? 'Would discard ' : 'Discarding ')
            ."{$discard->count()} line(s) of {$discard->map(fn ($r) => $r->payload['journal_header_id'])->unique()->count()} refused journal(s); keeping {$kept->count()}.");

        if (! $this->option('dry-run') && $discard->isNotEmpty()) {
            PendingSyncRecord::whereIn('id', $discard->pluck('id'))->delete();
        }

        return self::SUCCESS;
    }
}
