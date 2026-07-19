<?php

namespace App\Console\Commands\Zimra;

use App\Services\Zimra\ZimraSalesService;
use Illuminate\Console\Command;

class RetryFailedFiscalisations extends Command
{
    protected $signature = 'zimra:retry-failed';

    protected $description = 'Re-submit all pending/retry fiscalisations to ZIMRA, oldest first';

    public function handle(ZimraSalesService $service): int
    {
        $results = $service->retryFailedFiscalisations();

        $this->info(sprintf(
            'Attempted %d, fiscalised %d, still pending/failed %d.',
            $results['attempted'],
            $results['fiscalised'],
            $results['failed'],
        ));

        return self::SUCCESS;
    }
}
