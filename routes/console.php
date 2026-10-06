<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── ZIMRA fiscalisation ──────────────────────────────────────────────────────
// Close every device's fiscal day nightly — ZIMRA blocks a device whose day
// exceeds its max hours. The day re-opens automatically on the next morning's
// first sale (ZimraSalesService auto-open), so no scheduled open is needed.
Schedule::command('zimra:fiscal-day close --all')
    ->dailyAt('23:45')
    ->timezone('Africa/Harare')
    ->withoutOverlapping()
    ->onOneServer();

// Re-submit pending/failed fiscalisations left behind by ZIMRA outages.
Schedule::command('zimra:retry-failed')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// ── Recurring invoices ───────────────────────────────────────────────────────
Schedule::command('invoices:generate-recurring')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer();

// ── Accounting ───────────────────────────────────────────────────────────────
// Nothing is scheduled: the app posts every journal (sales, payments, GRVs,
// payroll, assets, depreciation) and syncs it up. The server only syncs. The
// old accounting:post-pending-* and post-asset-depreciation sweeps raced the
// app's own postings (2026-10-05: duplicate sale journals, the server's
// without COGS), so they no longer run.
