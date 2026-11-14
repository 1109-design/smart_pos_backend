<?php

namespace App\Console\Commands\Zimra;

use App\Services\Zimra\ZimraHealthMonitor;
use Illuminate\Console\Command;

class CheckFiscalisationHealth extends Command
{
    protected $signature = 'zimra:check-health';

    protected $description = 'Email the alert recipients about sales that have not been fiscalised';

    public function handle(ZimraHealthMonitor $monitor): int
    {
        $results = $monitor->check();

        $this->info(sprintf(
            'Checked %d business(es): %d with problems, %d email(s) sent.',
            $results['checked'],
            $results['with_problems'],
            $results['emails'],
        ));

        return self::SUCCESS;
    }
}
