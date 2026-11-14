<?php

namespace Tests\Feature;

use App\Models\PendingSyncRecord;
use App\Models\SyncConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscardOrphanJournalLinesTest extends TestCase
{
    use RefreshDatabase;

    private function stuckLine(string $uuid, string $header): void
    {
        PendingSyncRecord::create([
            'business_id' => 'biz',
            'table_name' => 'journal_lines',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => ['journal_header_id' => $header, 'debit' => 1],
            'attempts' => 10,
            'failed_permanently' => true,
            'last_error' => 'journal_lines: referenced parent does not exist yet.',
        ]);
    }

    public function test_only_lines_of_journals_refused_as_duplicates_are_discarded(): void
    {
        SyncConflict::create([
            'business_id' => 'biz',
            'table_name' => 'journal_headers',
            'record_uuid' => 'dup-header',
            'reason' => 'journal_headers: a journal already exists for sale:abc — refusing to post a duplicate.',
            'conflict_type' => 'processing_error',
            'status' => 'resolved',
        ]);
        $this->stuckLine('line-1', 'dup-header');
        $this->stuckLine('line-2', 'dup-header');
        $this->stuckLine('line-3', 'unknown-header');

        $this->artisan('sync:discard-orphan-journal-lines', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(3, PendingSyncRecord::count());

        $this->artisan('sync:discard-orphan-journal-lines')->assertSuccessful();
        $this->assertSame(['line-3'], PendingSyncRecord::pluck('record_uuid')->all());
    }
}
