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

// ── Accounting (Phase 11b) ───────────────────────────────────────────────────
// Catches any sale whose items/payments hadn't all synced yet when it first
// tried to post — see SalePostingService.
Schedule::command('accounting:post-pending-sales')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Phase 9 / 11d — catches up straight-line depreciation for every active
// asset. Daily is more than enough cadence for a monthly charge; idempotent
// either way.
Schedule::command('accounting:post-asset-depreciation')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
