<?php

namespace App\Console\Commands\Accounting;

use App\Models\Business;
use App\Services\Accounting\IntegrityCheckService;
use Illuminate\Console\Command;

/**
 * Phase 12.1 — the nightly read-only check of every live business's books.
 * Changes nothing; stores each run and logs any difference.
 */
class CheckIntegrity extends Command
{
    protected $signature = 'accounting:integrity-check {--business= : Check one business only}';

    protected $description = 'Check each business\'s books are whole (read-only).';

    public function handle(IntegrityCheckService $service): int
    {
        $businesses = Business::query()
            ->whereNotNull('accounting_go_live_date')
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->pluck('id');

        foreach ($businesses as $businessId) {
            $run = $service->run((string) $businessId);
            $this->line(($run->ok ? 'OK    ' : 'CHECK ').$businessId);
        }

        return self::SUCCESS;
    }
}
