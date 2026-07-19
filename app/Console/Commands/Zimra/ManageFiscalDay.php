<?php

namespace App\Console\Commands\Zimra;

use App\Models\Zimra\ZimraDevice;
use App\Services\Zimra\ZimraClient;
use App\Services\Zimra\ZimraCloseDayPayloadBuilder;
use Illuminate\Console\Command;

class ManageFiscalDay extends Command
{
    protected $signature = 'zimra:fiscal-day
        {action : open or close}
        {device_id : ZIMRA device ID}';

    protected $description = 'Open or close the fiscal day for a ZIMRA device';

    public function handle(ZimraClient $client, ZimraCloseDayPayloadBuilder $builder): int
    {
        $action = (string) $this->argument('action');
        $deviceId = (string) $this->argument('device_id');

        $device = ZimraDevice::where('device_id', $deviceId)->first();
        if (! $device) {
            $this->error("Device {$deviceId} not found.");

            return self::FAILURE;
        }

        $status = $client->getStatus($deviceId, $device);
        if (! $status['success']) {
            $this->error('GetStatus failed: '.$status['error']);

            return self::FAILURE;
        }

        $statusData = $status['data'];
        $this->info('Fiscal day status: '.($statusData['fiscalDayStatus'] ?? 'unknown'));

        if ($action === 'open') {
            if (($statusData['fiscalDayStatus'] ?? null) === 'FiscalDayOpened') {
                $this->info('Fiscal day is already open.');

                return self::SUCCESS;
            }

            $result = $client->openFiscalDay($deviceId, $device);
            if (! $result['success']) {
                $this->error('OpenDay failed: '.$result['error']);

                return self::FAILURE;
            }

            $this->info('Fiscal day opened (day no '.($result['data']['fiscalDayNo'] ?? '?').').');

            return self::SUCCESS;
        }

        if ($action === 'close') {
            if (($statusData['fiscalDayStatus'] ?? null) === 'FiscalDayClosed') {
                $this->info('Fiscal day is already closed.');

                return self::SUCCESS;
            }

            $fiscalDayNo = (int) ($statusData['fiscalDayNo'] ?? ($statusData['lastFiscalDayNo'] ?? 0));
            $lastReceiptCounter = isset($statusData['lastReceiptCounter']) ? (int) $statusData['lastReceiptCounter'] : null;

            $payload = $builder->build($device, $fiscalDayNo, $lastReceiptCounter);

            $result = $client->closeFiscalDay($deviceId, $payload, $device);
            if (! $result['success']) {
                $this->error('CloseDay failed: '.$result['error']);

                return self::FAILURE;
            }

            $device->update(['fiscal_day_opened_at' => null]);
            $this->info("Fiscal day {$fiscalDayNo} closed.");

            return self::SUCCESS;
        }

        $this->error("Unknown action '{$action}' — use open or close.");

        return self::INVALID;
    }
}
