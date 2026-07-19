<?php

namespace App\Console\Commands\Zimra;

use App\Models\Business;
use App\Models\Zimra\ZimraDevice;
use App\Services\Zimra\ZimraClient;
use Exception;
use Illuminate\Console\Command;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class RegisterZimraDevice extends Command
{
    protected $signature = 'zimra:register-device
        {business_id? : The business (tenant) this device belongs to — omit to pick from businesses without a fiscal device}
        {tin? : Taxpayer Identification Number}
        {device_id? : ZIMRA-issued device ID}
        {serial_no? : Device serial number}
        {activation_key? : ZIMRA activation key}';

    protected $description = 'Verify the taxpayer, generate a CSR, register the device with ZIMRA and store its certificate';

    public function handle(ZimraClient $client): int
    {
        $businessId = $this->argument('business_id') ?? $this->chooseUnfiscalisedBusiness();
        if ($businessId === null) {
            return self::FAILURE;
        }

        $business = Business::find($businessId);

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
     * Interactive picker over businesses that have no active fiscal device yet.
     * Returns the chosen business id, or null when every business is already
     * fiscalised (or none exist).
     */
    private function chooseUnfiscalisedBusiness(): ?string
    {
        $fiscalisedIds = ZimraDevice::where('is_active', true)->pluck('business_id');

        $candidates = Business::whereNotIn('id', $fiscalisedIds)
            ->orderBy('name')
            ->get(['id', 'name', 'tin']);

        if ($candidates->isEmpty()) {
            $this->error(Business::count() === 0
                ? 'No businesses found in the database.'
                : 'Every business already has an active fiscal device.');

            return null;
        }

        $options = $candidates->mapWithKeys(fn (Business $business) => [
            $business->id => $business->name
                .($business->tin ? " (TIN {$business->tin})" : ' (no TIN set)'),
        ])->all();

        return (string) select(
            label: 'Which business is this fiscal device for?',
            options: $options,
            scroll: 10,
        );
















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
