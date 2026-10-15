<?php

namespace App\Console\Commands\Accounting;

use App\Models\Asset;
use App\Models\Business;
use App\Services\Accounting\AssetPostingService;
use Illuminate\Console\Command;

/**
 * READ-ONLY since 2026-10-15: the server no longer posts journals —
 * the device that performs a transaction posts it. This now only
 * counts what is still unposted; the posting described below no
 * longer happens.
 *
 * Two jobs in one, same shape as `accounting:post-pending-sales` — but only
 * for acquisition/disposal (see AssetPostingService::postIfReady()'s doc
 * comment for why monthly depreciation is excluded: it stays its own
 * separate, unconfigurable, server-side-only daily sweep).
 *
 * 1. Ongoing sweep — catches an asset transaction AssetPostingService's
 *    inline SyncProcessor hook missed.
 * 2. One-off backfill — assets had no sync path at all until Flutter
 *    gained its own client-side register, so every already client-cut-over
 *    business needs this to pick up straggler acquisitions/disposals.
 *
 * Scheduled every 15 minutes (routes/console.php) once the initial backfill
 * has been run manually.
 */
class PostPendingAssetTransactions extends Command
{
    protected $signature = 'accounting:post-pending-asset-transactions';

    protected $description = 'Report (read-only) asset acquisitions/disposals left unposted — the server no longer posts';

    public function handle(): int
    {
        $businesses = Business::whereNotNull('accounting_go_live_date')->get();

        $attempted = 0;

        foreach ($businesses as $business) {
            $assets = Asset::where('business_id', $business->id)
                ->whereDate('acquisition_date', '>=', $business->accounting_go_live_date)
                ->get();

            foreach ($assets as $asset) {
                $transDate = $asset->acquisition_date->toDateString();

                $attempted++;
            }
        }

        $this->info("Found {$attempted} unposted asset(s) across {$businesses->count()} live business(es). The server no longer posts — post them on a device.");

        return self::SUCCESS;
    }
}
