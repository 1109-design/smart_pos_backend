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
