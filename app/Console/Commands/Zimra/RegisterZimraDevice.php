<?php

namespace App\Console\Commands\Zimra;

use App\Models\Business;
use App\Models\Location;
use App\Models\Zimra\ZimraDevice;
use App\Services\Zimra\ZimraClient;
use Exception;
use Illuminate\Console\Command;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class RegisterZimraDevice extends Command
{
    protected $signature = 'zimra:register-device
        {business_id? : The business (tenant) this device belongs to — omit to pick interactively}
        {location_id? : The branch/location this device is registered for — omit for a business-wide fallback device, or to pick interactively}
        {tin? : Taxpayer Identification Number}
        {device_id? : ZIMRA-issued device ID}
        {serial_no? : Device serial number}
        {activation_key? : ZIMRA activation key}';

    protected $description = 'Verify the taxpayer, generate a CSR, register the device with ZIMRA and store its certificate';

    public function handle(ZimraClient $client): int
    {
        $businessId = $this->argument('business_id') ?? $this->chooseBusiness();
        if ($businessId === null) {
            return self::FAILURE;
        }

        $business = Business::find($businessId);

        $locationId = $this->argument('location_id')
            ?? $this->chooseLocation((string) $businessId);

        $tin = $this->argument('tin') ?? text(
            label: 'Taxpayer Identification Number (TIN)',
            default: (string) ($business?->tin ?? ''),
            required: true,
            validate: fn (string $value) => preg_match('/^\d{10}$/', trim($value))
                ? null
                : 'TIN must be exactly 10 digits',
        );
        $deviceId = $this->argument('device_id') ?? text(
            label: 'ZIMRA device ID',
            required: true,
            validate: fn (string $value) => ctype_digit(trim($value)) ? null : 'Device ID must be numeric',
        );
        $serialNo = $this->argument('serial_no') ?? text(label: 'Device serial number', required: true);
        $activationKey = $this->argument('activation_key') ?? text(label: 'Activation key', required: true);

        $businessId = (string) $businessId;
        $tin = trim((string) $tin);
        $deviceId = trim((string) $deviceId);
        $serialNo = trim((string) $serialNo);
        $activationKey = trim((string) $activationKey);

        $this->info('Verifying taxpayer information with ZIMRA…');
        $verify = $client->verifyTaxpayer($deviceId, $activationKey, $serialNo);
        if (! $verify['success']) {
            $this->error('Taxpayer verification failed: '.$verify['error']);

            return self::FAILURE;
        }
        $this->info('Taxpayer verified: '.($verify['data']['taxPayerName'] ?? 'OK'));

        try {
            [$csrPem, $privateKeyPem] = $this->generateCsr($deviceId, $serialNo);
        } catch (Exception $e) {
            $this->error('CSR generation failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Registering device with ZIMRA…');
        $register = $client->registerDevice($deviceId, $activationKey, $csrPem);
        if (! $register['success']) {
            $this->error('Device registration failed: '.$register['error']);

            return self::FAILURE;
        }

        $certificate = $register['data']['certificate'] ?? null;
        if (! $certificate) {
            $this->error('ZIMRA did not return a certificate.');

            return self::FAILURE;
        }

        $device = ZimraDevice::updateOrCreate(
            ['device_id' => $deviceId],
            [
                'business_id' => $businessId,
                'location_id' => $locationId ?: null,
                'tin' => $tin,
                'device_serial_no' => $serialNo,
                'activation_key' => $activationKey,
                'is_active' => true,
                'status' => 'active',
                'certificate_data' => $certificate,
                'private_key_data' => $privateKeyPem,
            ]
        );

        $this->info('Fetching device config (tax codes)…');
        $config = $client->getDeviceConfig($deviceId, $device);
        if (! $config['success']) {
            $this->warn('GetConfig failed (will retry before first submission): '.$config['error']);
        }

        if ($business && empty($business->tin)) {
            $business->update(['tin' => $tin]);
        }

        $this->info("Device {$deviceId} registered and active for business {$businessId}.");

        return self::SUCCESS;
    }

    /**
     * Interactive picker over every business. A chain registers one device
     * per branch, so — unlike the old single-device-per-business assumption —
     * a business already having active devices is not excluded; its current
     * device count is shown so the admin can tell at a glance who still
     * needs one.
     */
    private function chooseBusiness(): ?string
    {
        $deviceCounts = ZimraDevice::where('is_active', true)
            ->selectRaw('business_id, COUNT(*) as device_count')
            ->groupBy('business_id')
            ->pluck('device_count', 'business_id');

        $candidates = Business::orderBy('name')->get(['id', 'name', 'tin']);

        if ($candidates->isEmpty()) {
            $this->error('No businesses found in the database.');

            return null;
        }

        $options = $candidates->mapWithKeys(function (Business $business) use ($deviceCounts) {
            $count = (int) ($deviceCounts[$business->id] ?? 0);
            $status = $count > 0
                ? " ({$count} active device".($count === 1 ? '' : 's').')'
                : ' (no device yet)';

            return [$business->id => $business->name
                .($business->tin ? " (TIN {$business->tin})" : ' (no TIN set)')
                .$status];
        })->all();

        return (string) select(
            label: 'Which business is this fiscal device for?',
            options: $options,
            scroll: 10,
        );
    }

    /**
     * Interactive picker over a business's locations, plus a "business-wide"
     * fallback option (null). Skips the prompt entirely when the business has
     * no locations recorded yet, so single-location businesses are unaffected.
     */
    private function chooseLocation(string $businessId): ?string
    {
        $locations = Location::where('business_id', $businessId)
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        if ($locations->isEmpty()) {
            return null;
        }

        $options = ['' => 'Business-wide (no specific branch / fallback device)']
            + $locations->mapWithKeys(fn (Location $location) => [
                $location->id => $location->name.($location->type ? " ({$location->type})" : ''),
            ])->all();

        $choice = select(
            label: 'Which branch is this fiscal device physically at?',
            options: $options,
            scroll: 10,
        );

        return $choice === '' ? null : (string) $choice;

    }

    /**
     * Generate an RSA-2048 CSR per ZIMRA spec: CN = ZIMRA-{serial}-{deviceId
     * left-padded to 10 digits}, C=ZW.
     *
     * @return array{0: string, 1: string} [csrPem, privateKeyPem]
     */
    private function generateCsr(string $deviceId, string $serialNo): array
    {
        $dn = [
            'countryName' => 'ZW',
            'stateOrProvinceName' => 'Zimbabwe',
            'organizationName' => 'Zimbabwe Revenue Authority',
            'commonName' => 'ZIMRA-'.$serialNo.'-'.str_pad($deviceId, 10, '0', STR_PAD_LEFT),
        ];

        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];

        $privKey = openssl_pkey_new($config);
        if ($privKey === false) {
            throw new Exception('Failed to generate private key: '.openssl_error_string());
        }

        $csr = openssl_csr_new($dn, $privKey, $config);
        if ($csr === false) {
            throw new Exception('Failed to generate CSR: '.openssl_error_string());
        }

        if (! openssl_csr_export($csr, $csrPem)) {
            throw new Exception('Failed to export CSR: '.openssl_error_string());
        }

        if (! openssl_pkey_export($privKey, $privKeyPem)) {
            throw new Exception('Failed to export private key: '.openssl_error_string());
        }

        return [$csrPem, $privKeyPem];
    }
}
