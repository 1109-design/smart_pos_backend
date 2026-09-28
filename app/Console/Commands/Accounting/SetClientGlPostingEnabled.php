<?php

namespace App\Console\Commands\Accounting;

use App\Models\Business;
use Illuminate\Console\Command;

/**
 * Flips a business over to Flutter posting its own sale/GRV journals
 * locally — see the client_gl_posting_enabled_at migration and
 * Business::postsFromClientFor(). Only flip this once every active
 * till/device for the business is confirmed on an app version that posts
 * locally: an older device won't know to post its own journal, and will
 * rely on the accounting:post-pending-sales sweep's grace-period fallback
 * to eventually get posted server-side instead.
 */
class SetClientGlPostingEnabled extends Command
{
    protected $signature = 'accounting:set-client-gl-posting-enabled
        {business : The tenant/business id}
        {datetime? : Y-m-d H:i:s — transactions on or after this instant post client-side. Defaults to now.}';

    protected $description = 'Cut a business over to client-side (Flutter) GL posting for sales and GRV receiving';

    public function handle(): int
    {
        $business = Business::find($this->argument('business'));

        if (! $business) {
            $this->error('No matching business found.');

            return self::FAILURE;
        }

        $cutover = $this->argument('datetime') ?? now()->toDateTimeString();

        $business->update(['client_gl_posting_enabled_at' => $cutover]);
        $business->publishAccountingSettingsSyncRecord();

        $this->info("Client-side GL posting is now live for {$business->id} from {$cutover} onward.");

        return self::SUCCESS;
    }
}
