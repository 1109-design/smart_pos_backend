<?php

namespace App\Console\Commands\Zimra;

use App\Models\Zimra\ZimraDevice;
use App\Services\Zimra\ZimraFiscalDayService;
use Illuminate\Console\Command;

class ReprobeBlockedDevices extends Command
{
    protected $signature = 'zimra:reprobe-blocked-devices';

    protected $description = 'Recover devices held by a failed or unusable fiscal day, or flag them for closing in the ZIMRA Public Portal';

    public function handle(ZimraFiscalDayService $fiscalDays): int
    {
        foreach (ZimraDevice::where('is_active', true)->get() as $device) {
            $result = $fiscalDays->reprobe($device);
            $this->line("Device {$device->device_id}: {$result['message']}");
        }

        return self::SUCCESS;
    }
}
