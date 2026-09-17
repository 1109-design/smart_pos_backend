<?php

namespace App\Console\Commands\Accounting;

use App\Models\Asset;
use App\Models\Business;
use App\Services\Accounting\AssetPostingService;
use Illuminate\Console\Command;

/**
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
    private const GRACE_PERIOD_HOURS = 1;

    protected $signature = 'accounting:post-pending-asset-transactions';

    protected $description = 'Post accounting entries for asset acquisitions/disposals left unposted (sweep + one-off backfill)';

    public function handle(AssetPostingService $posting): int
    {
        $businesses = Business::whereNotNull('accounting_go_live_date')->get();

        $attempted = 0;

        foreach ($businesses as $business) {
            $assets = Asset::where('business_id', $business->id)
                ->whereDate('acquisition_date', '>=', $business->accounting_go_live_date)
                ->get();

            foreach ($assets as $asset) {
                $transDate = $asset->acquisition_date->toDateString();
                $viaSweep = $business->postsFromClientFor($transDate)
                    && $asset->acquisition_date->lte(now()->subHours(self::GRACE_PERIOD_HOURS));

                $posting->postIfReady($asset, $viaSweep);
                $attempted++;
            }
        }

        $this->info("Attempted {$attempted} pending asset(s) across {$businesses->count()} live business(es).");

        return self::SUCCESS;
    }
}
