<?php

namespace App\Console\Commands\Accounting;

use App\Services\Accounting\AssetPostingService;
use Illuminate\Console\Command;

/**
 * Phase 9 / Phase 11d — the monthly depreciation sweep. Scheduled once a
 * day (routes/console.php); idempotent, so running it more often than
 * strictly necessary is harmless — AssetPostingService::postMonthlyDepreciation()
 * only ever posts the shortfall between elapsed months and journals already
 * on record for each asset.
 */
class PostAssetDepreciation extends Command
{
    protected $signature = 'accounting:post-asset-depreciation';

    protected $description = 'Post straight-line depreciation for every active asset up to the current month';

    public function handle(AssetPostingService $postings): int
    {
        $count = $postings->postMonthlyDepreciation();

        $this->info("Posted {$count} depreciation journal(s).");

        return self::SUCCESS;
    }
}
