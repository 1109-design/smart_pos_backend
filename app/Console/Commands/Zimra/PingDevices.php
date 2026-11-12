<?php

namespace App\Console\Commands\Zimra;

use App\Models\Zimra\ZimraDevice;
use App\Services\Zimra\ZimraClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PingDevices extends Command
{
    protected $signature = 'zimra:ping';

    protected $description = 'Tell ZIMRA that every active fiscal device is online';

    public function handle(ZimraClient $client): int
    {
        $failures = 0;

        foreach (ZimraDevice::where('is_active', true)->get() as $device) {
            $result = $client->ping((string) $device->device_id, $device);

            if ($result['success']) {
                $device->update(['last_sync_at' => now()]);
                $this->line("Device {$device->device_id}: ping OK, reporting frequency ".($result['data']['reportingFrequency'] ?? 'not given').'.');

                continue;
            }

            $failures++;
            // The scheduler discards command output, so the log is the only record.
            Log::warning('ZIMRA ping failed', ['device_id' => (string) $device->device_id, 'error' => $result['error']]);
            $this->error("Device {$device->device_id}: ping failed: {$result['error']}");
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
