<?php

namespace App\Services\Zimra;

use App\Models\Zimra\ZimraConfiguration;
use App\Models\Zimra\ZimraDevice;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for the ZIMRA FDMS APIs.
 *
 * Device API calls (GetStatus, GetConfig, SubmitReceipt, OpenDay, CloseDay,
 * IssueCertificate) authenticate over mTLS using the device's own client
 * certificate + private key. The Public API is only used for onboarding
 * (VerifyTaxpayerInformation, RegisterDevice).
 */
class ZimraClient
{
    private ZimraConfiguration $config;

    public function __construct()
    {
        $this->config = ZimraConfiguration::getCurrent() ?? $this->createDefaultConfig();
    }

    public function getConfig(): ZimraConfiguration
    {
        return $this->config;
    }

    // ── Public API (onboarding — no mTLS) ────────────────────────────────────

    /**
     * Verify taxpayer information before registration (modern ZIMRA flow step 1).
     */
    public function verifyTaxpayer(string $deviceId, string $activationKey, string $deviceSerialNo): array
    {
        try {
            $response = $this->basePublicRequest()
                ->withBody(json_encode([
                    'activationKey' => $activationKey,
                    'deviceSerialNo' => $deviceSerialNo,
                ]), 'application/json')
                ->post($this->publicUrl("/Public/v1/{$deviceId}/VerifyTaxpayerInformation"));

            return $this->wrapResponse($response);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Register a device with ZIMRA. When a CSR is provided ZIMRA returns the
     * signed device certificate used for all subsequent mTLS calls.
     */
    public function registerDevice(string $deviceId, string $activationKey, ?string $csr = null): array
    {
        if ($blocked = $this->guardBlock('RegisterDevice')) {
            return $blocked;
        }

        try {
            $payload = ['activationKey' => $activationKey];
            if ($csr) {
                $payload['certificateRequest'] = $csr;
            }

            $response = $this->basePublicRequest()
                ->withBody(json_encode($payload), 'application/json')
                ->post($this->publicUrl("/Public/v1/{$deviceId}/RegisterDevice"));

            return $this->wrapResponse($response);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Device API (mTLS) ────────────────────────────────────────────────────

    /**
     * Device status: fiscalDayStatus, lastReceiptGlobalNo, lastReceiptCounter.
     */
    public function getStatus(string $deviceId, ZimraDevice $device): array
    {
        return $this->deviceRequest($device, 'GET', "/Device/v1/{$deviceId}/GetStatus");
    }

    /**
     * GetConfig: fetches applicable taxes and fiscal-day limits; persists the
     * taxID => taxCode map onto the device for use in receipt signing.
     */
    public function getDeviceConfig(string $deviceId, ZimraDevice $device): array
    {
        $result = $this->deviceRequest($device, 'GET', "/Device/v1/{$deviceId}/GetConfig");

        if (! $result['success']) {
            return $result;
        }

        $data = $result['data'];

        // ZIMRA returns 'applicableTaxes' with taxID/taxPercent/taxName. Store the
        // full list so the formatter can resolve the correct taxID for each item's
        // tax rate without hardcoded assumptions (prevents RCPT025).
        $taxCodeMap = [];
        $applicableTaxes = [];
        foreach ($data['taxPercents'] ?? $data['applicableTaxes'] ?? [] as $taxConfig) {
            $taxId = $taxConfig['taxID'] ?? null;
            if ($taxId === null) {
                continue;
            }
            $taxCodeMap[$taxId] = $taxConfig['taxCode'] ?? '';
            $applicableTaxes[] = [
                'taxID' => (int) $taxId,
                'taxPercent' => isset($taxConfig['taxPercent']) ? (float) $taxConfig['taxPercent'] : null,
                'taxName' => $taxConfig['taxName'] ?? '',
            ];
        }

        $updates = [];
        if (! empty($taxCodeMap)) {
            $updates['tax_codes'] = $taxCodeMap;
            $updates['applicable_taxes'] = $applicableTaxes;
        }

        $maxHours = $data['taxPayerDayMaxHrs'] ?? $data['taxpayerDayMaxHrs'] ?? null;
        if ($maxHours !== null) {
            $updates['fiscal_day_max_hours'] = (int) $maxHours;
        }

        if (! empty($updates)) {
            $device->update($updates);
        }

        return ['success' => true, 'data' => $data, 'tax_code_map' => $taxCodeMap];
    }

    /**
     * Renew a device certificate before it expires (spec §4.3).
     *
     * ⚠  Do NOT use this to recover from BadCertificateSignature on close day —
     *    it revokes the previous cert immediately. If a fiscal day is open, the
     *    new cert will not be accepted for that day's close-day signing, leaving
     *    the device locked out. Use registerDevice() for that recovery instead.
     *    Only call when the cert is near expiry AND no fiscal day is open.
     */
    public function issueCertificate(string $deviceId, string $csrPem, ZimraDevice $device): array
    {
        if ($blocked = $this->guardBlock('IssueCertificate')) {
            return $blocked;
        }

        return $this->deviceRequest($device, 'POST', "/Device/v1/{$deviceId}/IssueCertificate", [
            'certificateRequest' => $csrPem,
        ]);
    }

    /**
     * Submit a formatted receipt for fiscalisation. Expects the full payload
     * from ZimraReceiptFormatter (['receipt' => [...]]).
     */
    public function submitReceipt(string $deviceId, array $receiptData, ZimraDevice $device): array
    {
        if ($blocked = $this->guardBlock('SubmitReceipt')) {
            return $blocked;
        }

        $jsonBody = self::encodeReceiptJson(['receipt' => $receiptData['receipt']]);

        Log::info('ZIMRA SubmitReceipt request', [
            'device_id' => $deviceId,
            'invoice_no' => $receiptData['receipt']['invoiceNo'] ?? null,
            'receipt_global_no' => $receiptData['receipt']['receiptGlobalNo'] ?? null,
        ]);

        $result = $this->deviceRequest($device, 'POST', "/Device/v1/{$deviceId}/SubmitReceipt", null, $jsonBody);

        Log::info('ZIMRA SubmitReceipt response', [
            'device_id' => $deviceId,
            'success' => $result['success'],
            'receipt_id' => $result['data']['receiptID'] ?? null,
            'error' => $result['error'] ?? null,
        ]);

        return $result;
    }

    public function openFiscalDay(string $deviceId, ZimraDevice $device): array
    {
        if ($blocked = $this->guardBlock('OpenDay')) {
            return $blocked;
        }

        $result = $this->deviceRequest($device, 'POST', "/Device/v1/{$deviceId}/OpenDay", [
            'fiscalDayOpened' => now('Africa/Harare')->format('Y-m-d\TH:i:s'),
        ]);

        if ($result['success']) {
            // Stamp fiscal_day_opened_at so previousReceiptHash queries are scoped
            // to the current fiscal day only (spec §2.3: hash chains are per day).
            $device->update(['fiscal_day_opened_at' => now()]);
        }

        return $result;
    }

    public function closeFiscalDay(string $deviceId, array $fiscalDayData, ZimraDevice $device): array
    {
        if ($blocked = $this->guardBlock('CloseDay')) {
            return $blocked;
        }

        return $this->deviceRequest($device, 'POST', "/Device/v1/{$deviceId}/CloseDay", $fiscalDayData);
    }

    /**
     * Encode receipt data to JSON with shortest-float precision so 6-decimal
     * receiptLinePrice values (spec §2.5) survive serialisation intact.
     */
    public static function encodeReceiptJson(array $data): string
    {
        $oldPrecision = ini_get('serialize_precision');
        ini_set('serialize_precision', -1);

        try {
            return json_encode($data);
        } finally {
            ini_set('serialize_precision', $oldPrecision);
        }
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Perform an mTLS-authenticated Device API call. The device cert + key are
     * written to temp PEM files for the duration of the request and always
     * cleaned up, success or failure.
     *
     * @return array{success: bool, data?: array, error?: string, status_code?: int}
     */
    private function deviceRequest(ZimraDevice $device, string $method, string $path, ?array $jsonPayload = null, ?string $rawBody = null): array
    {
        $certFile = null;
        $keyFile = null;

        try {
            $request = $this->baseDeviceRequest();

            if ($device->certificate_data && $device->private_key_data) {
                $certificate = openssl_x509_read($device->certificate_data);
                $privateKey = openssl_pkey_get_private($device->private_key_data);

                if (! $certificate || ! $privateKey) {
                    return [
                        'success' => false,
                        'error' => 'Invalid device certificate/private key format. Re-store certificate and key in PEM format.',
                    ];
                }

                if (! openssl_x509_check_private_key($certificate, $privateKey)) {
                    return [
                        'success' => false,
                        'error' => 'Device certificate and private key do not match. Re-register device to get a matching pair.',
                    ];
                }

                $certFile = tempnam(sys_get_temp_dir(), 'zimra_cert_');
                $keyFile = tempnam(sys_get_temp_dir(), 'zimra_key_');

                openssl_x509_export($certificate, $certificatePem);
                openssl_pkey_export($privateKey, $privateKeyPem);
                file_put_contents($certFile, $certificatePem);
                file_put_contents($keyFile, $privateKeyPem);

                $request = $request->withOptions([
                    'cert' => $certFile,
                    'ssl_key' => $keyFile,
                    // Production MUST verify ZIMRA's TLS certificate — skipping
                    // verification on the fiscal channel invites MITM. The test
                    // environment keeps the relaxed behaviour because ZIMRA's
                    // test endpoint has historically presented an incomplete
                    // certificate chain.
                    'verify' => $this->config->isProduction(),
                ]);
            }

            $url = $this->deviceUrl($path);

            if ($rawBody !== null) {
                $response = $request->withBody($rawBody, 'application/json')->post($url);
            } elseif ($method === 'POST') {
                $response = $request->withBody(json_encode($jsonPayload ?? []), 'application/json')->post($url);
            } else {
                $response = $request->get($url);
            }

            return $this->wrapResponse($response);
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            if ($certFile && file_exists($certFile)) {
                unlink($certFile);
            }
            if ($keyFile && file_exists($keyFile)) {
                unlink($keyFile);
            }
        }
    }

    private function baseDeviceRequest(): PendingRequest
    {
        return Http::timeout($this->config->timeout_seconds)
            ->connectTimeout($this->config->connectTimeoutSeconds())
            ->withHeaders([
                'DeviceModelName' => 'Server',
                'DeviceModelVersion' => 'v1',
                'Content-Type' => 'application/json',
            ]);
    }

    private function basePublicRequest(): PendingRequest
    {
        return Http::timeout($this->config->timeout_seconds)
            ->connectTimeout($this->config->connectTimeoutSeconds())
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'DeviceModelName' => 'Server',
                'DeviceModelVersion' => 'v1',
            ]);
    }

    private function deviceUrl(string $path): string
    {
        return str_replace('/api/v1/device', '', $this->config->device_api_url).$path;
    }

    private function publicUrl(string $path): string
    {
        return str_replace('/api/v1/public', '', $this->config->public_api_url).$path;
    }

    private function wrapResponse($response): array
    {
        if ($response->successful()) {
            return ['success' => true, 'data' => $response->json()];
        }

        return [
            'success' => false,
            'error' => 'HTTP '.$response->status().': '.$response->body(),
            'status_code' => $response->status(),
        ];
    }

    private function createDefaultConfig(): ZimraConfiguration
    {
        $environment = config('zimra.environment', 'production');
        $host = $environment === 'test'
            ? 'https://fdmsapitest.zimra.co.zw'
            : 'https://fdmsapi.zimra.co.zw';

        return ZimraConfiguration::create([
            'environment' => $environment,
            'public_api_url' => $host.'/api/v1/public',
            'device_api_url' => $host.'/api/v1/device',
            'is_enabled' => true,
            'timeout_seconds' => 30,
            'max_retries' => 3,
        ]);
    }

    /**
     * Hard backstop for every state-changing ZIMRA call.
     *
     * @return array{success: false, error: string, guard_blocked: true}|null
     */
    private function guardBlock(string $operation): ?array
    {
        $guard = app(FiscalisationGuard::class);

        if ($guard->allows()) {
            return null;
        }

        $reason = (string) $guard->blockReason();

        Log::warning("ZIMRA {$operation} blocked by fiscalisation guard", [
            'operation' => $operation,
            'reason' => $reason,
        ]);

        return ['success' => false, 'error' => $reason, 'guard_blocked' => true];
    }
}
