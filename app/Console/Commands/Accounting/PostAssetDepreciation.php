<?php

namespace App\Console\Commands\Accounting;

use Illuminate\Console\Command;

/**
 * RETIRED 2026-10-15: the server no longer posts journals; the app's
 * DepreciationPostingService posts depreciation on the device.
 *
 * Phase 9 / Phase 11d — the monthly depreciation sweep. Scheduled once a
 * day (routes/console.php); idempotent, so running it more often than
 * strictly necessary is harmless — AssetPostingService::postMonthlyDepreciation()
 * only ever posts the shortfall between elapsed months and journals already
 * on record for each asset.
 */
class PostAssetDepreciation extends Command
{
    protected $signature = 'accounting:post-asset-depreciation';

    protected $description = 'Retired: depreciation is posted by the app — the server no longer posts';

    public function handle(): int
    {
        $this->info('Depreciation is posted by the app (DepreciationPostingService) — the server no longer posts. Nothing to do here.');

        return self::SUCCESS;
    }
}
