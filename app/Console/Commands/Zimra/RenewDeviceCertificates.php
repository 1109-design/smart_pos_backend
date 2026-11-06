<?php

namespace App\Console\Commands\Zimra;

use App\Models\Zimra\ZimraDevice;
use App\Services\Zimra\ZimraCertificateService;
use Illuminate\Console\Command;

class RenewDeviceCertificates extends Command
{
    protected $signature = 'zimra:renew-certificates
        {device_id? : Only this ZIMRA device; omit for every active device}
        {--force : Renew even if the certificate is not close to expiring}';

    protected $description = 'Renew ZIMRA device certificates through IssueCertificate when they are close to expiring';

    public function handle(ZimraCertificateService $certificates): int
    {
        $deviceId = $this->argument('device_id');
        $devices = ZimraDevice::query()
            ->when($deviceId, fn ($query) => $query->where('device_id', $deviceId), fn ($query) => $query->where('is_active', true))
            ->get();

        if ($deviceId && $devices->isEmpty()) {
            $this->error("Device {$deviceId} not found.");

            return self::FAILURE;
        }

        $failures = 0;
        foreach ($devices as $device) {
            $result = $certificates->renewIfDue($device, (bool) $this->option('force'));
            $this->line("Device {$device->device_id}: {$result['message']}");
            $failures += $result['outcome'] === ZimraCertificateService::FAILED ? 1 : 0;
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
