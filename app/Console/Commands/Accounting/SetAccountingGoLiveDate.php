<?php

namespace App\Console\Commands\Accounting;

use App\Models\Business;
use Illuminate\Console\Command;

/**
 * Stopgap until Phase 11f's real BackOffice setting exists. Setting this is
 * the moment a business commits to "opening balances, not a historical
 * rebuild" — see the accounting_go_live_date migration and
 * SalePostingService. Before this is set, no sale ever posts for the
 * business, live or via the accounting:post-pending-sales sweep.
 */
class SetAccountingGoLiveDate extends Command
{
    protected $signature = 'accounting:set-go-live-date
        {business : The tenant/business id}
        {date : Y-m-d — sales on or after this date will start posting}';

    protected $description = 'Set the date accounting auto-posting starts for a business';

    public function handle(): int
    {
        $business = Business::find($this->argument('business'));

        if (! $business) {
            $this->error('No matching business found.');

            return self::FAILURE;
        }

        $business->update(['accounting_go_live_date' => $this->argument('date')]);

        $this->info("Accounting posting is now live for {$business->id} from {$this->argument('date')} onward.");

        return self::SUCCESS;
    }
}
