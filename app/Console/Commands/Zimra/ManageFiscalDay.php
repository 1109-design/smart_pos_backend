<?php

namespace App\Console\Commands\Zimra;

use App\Models\Zimra\ZimraDevice;
use App\Services\Zimra\ZimraClient;
use App\Services\Zimra\ZimraFiscalDayService;
use Illuminate\Console\Command;

class ManageFiscalDay extends Command
{
    protected $signature = 'zimra:fiscal-day
        {action : open, close, or confirm (check an unconfirmed close with ZIMRA)}
        {device_id? : ZIMRA device ID}
        {--all : Run the action for every active fiscal device (used by the scheduler)}';

    protected $description = 'Open or close the fiscal day for a ZIMRA device, or confirm a close';

    public function handle(ZimraClient $client, ZimraFiscalDayService $fiscalDays): int
    {
        $action = (string) $this->argument('action');

        if ($this->option('all')) {
            $devices = ZimraDevice::where('is_active', true)->get();
            if ($devices->isEmpty()) {
                $this->info('No active fiscal devices.');

                return self::SUCCESS;
            }

            $failures = 0;
            foreach ($devices as $device) {
                $this->info("— device {$device->device_id}:");
                if ($this->runForDevice($client, $fiscalDays, $action, $device) !== self::SUCCESS) {
                    $failures++;
                }
            }

            return $failures === 0 ? self::SUCCESS : self::FAILURE;
        }

        $deviceId = (string) $this->argument('device_id');
        if ($deviceId === '') {
            $this->error('Provide a device_id or use --all.');

            return self::INVALID;
        }

        $device = ZimraDevice::where('device_id', $deviceId)->first();
        if (! $device) {
            $this->error("Device {$deviceId} not found.");

            return self::FAILURE;
        }

        return $this->runForDevice($client, $fiscalDays, $action, $device);
    }

    private function runForDevice(ZimraClient $client, ZimraFiscalDayService $fiscalDays, string $action, ZimraDevice $device): int
    {
        if ($action === 'confirm') {
            return $this->report($fiscalDays->confirm($device));
        }

        if ($action === 'close') {
            return $this->report($fiscalDays->close($device));
        }

        $deviceId = (string) $device->device_id;

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

        $this->error("Unknown action '{$action}' — use open, close or confirm.");

        return self::INVALID;
    }

    /**
     * @param  array{outcome: string, message: string}  $result
     */
    private function report(array $result): int
    {
        if ($result['outcome'] === ZimraFiscalDayService::CLOSED) {
            $this->info($result['message']);

            return self::SUCCESS;
        }

        if ($result['outcome'] === ZimraFiscalDayService::PENDING) {
            $this->warn($result['message'].' It is checked again every 15 minutes.');

            return self::SUCCESS;
        }

        $this->error($result['message']);

        return self::FAILURE;
    }
}
