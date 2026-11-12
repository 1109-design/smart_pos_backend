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

// ZIMRA finishes validating a close after accepting it, so check on any close it
// hasn't confirmed. Only devices with an unconfirmed close call ZIMRA.
Schedule::command('zimra:fiscal-day confirm --all')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Re-submit pending/failed fiscalisations left behind by ZIMRA outages.
Schedule::command('zimra:retry-failed')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Get a device moving again after a failed or stuck fiscal day, or tell someone
// it has to be closed in the ZIMRA Public Portal.
Schedule::command('zimra:reprobe-blocked-devices')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Email the alert recipients when sales stop being fiscalised, so nobody has to
// be looking at a till to find out.
Schedule::command('zimra:check-health')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// ZIMRA requires every device to report in once every 5 minutes.
Schedule::command('zimra:ping')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Renew certificates close to expiring. Runs after the 23:45 close, because a
// certificate is only replaced while the fiscal day is closed.
Schedule::command('zimra:renew-certificates')
    ->dailyAt('00:15')
    ->timezone('Africa/Harare')
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
