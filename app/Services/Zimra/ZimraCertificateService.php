<?php

namespace App\Services\Zimra;

use App\Models\Zimra\ZimraDevice;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Renews device certificates through IssueCertificate before they expire. ZIMRA
 * only honours it while the current certificate is valid, so a missed window
 * means registering the device again. Validity comes from GetConfig.
 */
class ZimraCertificateService
{
    public const RENEWED = 'renewed';

    public const NOT_DUE = 'not_due';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public const BLOCKED = 'blocked';

    private const RENEW_WITHIN_DAYS = 30;

    /** From here on, anything stopping a renewal is worth telling someone about. */
    private const URGENT_WITHIN_DAYS = 7;

    public function __construct(
        private ZimraClient $client,
        private FiscalisationGuard $guard,
    ) {}

    /**
     * Renew the certificate if it is close to expiring, or whenever asked with $force.
     *
     * @return array{outcome: string, message: string}
     */
    public function renewIfDue(ZimraDevice $device, bool $force = false): array
    {
        if ($reason = $this->guard->blockReason()) {
            return ['outcome' => self::BLOCKED, 'message' => $reason];
        }

        $config = $this->client->getDeviceConfig((string) $device->device_id, $device);
        $device->refresh();

        $expiresAt = ($config['success'] ? $device->certificate_expires_at : null) ?? $this->expiryFromCertificate($device);

        if ($expiresAt?->isPast()) {
            return $this->fail($device, 'The certificate expired on '.$expiresAt->toDateTimeString().' UTC, so ZIMRA will not renew it. Register the device again with zimra:register-device.');
        }

        // A renewal can succeed at ZIMRA with the reply lost on the way back. ZIMRA
        // revokes the old certificate at once, so this device is now locked out.
        if (($config['status_code'] ?? null) === 401) {
            return $this->fail($device, 'ZIMRA refused the device certificate (HTTP 401) although it has not expired, so it may have been revoked. Register the device again with zimra:register-device.');
        }

        if (! $config['success']) {
            return $this->holdOff($device, $expiresAt, 'GetConfig failed: '.$config['error']);
        }

        if (! $force && $expiresAt && $expiresAt->gt(now()->addDays(self::RENEW_WITHIN_DAYS))) {
            return ['outcome' => self::NOT_DUE, 'message' => 'Certificate is valid until '.$expiresAt->toDateTimeString().' UTC.'];
        }

        return $this->renewUnderDeviceLock($device, $expiresAt);
    }

    /**
     * Held under the lock receipts are signed under, so a sale can't open a fiscal
     * day or sign with the old key while the key is being replaced.
     *
     * @return array{outcome: string, message: string}
     */
    private function renewUnderDeviceLock(ZimraDevice $device, ?Carbon $expiresAt): array
    {
        $lockName = 'zimra_device_'.$device->device_id;
        $usingMysqlLock = DB::connection()->getDriverName() === 'mysql';

        if ($usingMysqlLock) {
            $acquired = DB::selectOne('SELECT GET_LOCK(?, 60) as acquired', [$lockName]);
            if (! $acquired || ! $acquired->acquired) {
                return $this->holdOff($device, $expiresAt, 'The device is busy submitting receipts.');
            }
        }

        try {
            $status = $this->client->getStatus((string) $device->device_id, $device);
            if (! $status['success']) {
                return $this->holdOff($device, $expiresAt, 'GetStatus failed: '.$status['error']);
            }

            // A certificate issued mid-day isn't accepted for that day's close
            // signature, which would leave the day unable to close.
            if (($status['data']['fiscalDayStatus'] ?? null) !== 'FiscalDayClosed') {
                return $this->holdOff($device, $expiresAt, 'The fiscal day is not closed, so the certificate was not renewed.');
            }

            return $this->issue($device);
        } finally {
            if ($usingMysqlLock) {
                DB::selectOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }

    /**
     * The new key is stored only together with its certificate, so a failed
     * request leaves the device with the working pair it already had.
     *
     * @return array{outcome: string, message: string}
     */
    private function issue(ZimraDevice $device): array
    {
        try {
            [$csrPem, $privateKeyPem] = self::generateCsr((string) $device->device_id, (string) $device->device_serial_no);
        } catch (Exception $e) {
            return $this->fail($device, 'Could not generate a certificate request: '.$e->getMessage());
        }

        $result = $this->client->issueCertificate((string) $device->device_id, $csrPem, $device);
        if (! $result['success']) {
            return $this->fail($device, 'IssueCertificate failed: '.$result['error']);
        }

        $certificatePem = $result['data']['certificate'] ?? null;
        $certificate = $certificatePem ? openssl_x509_read($certificatePem) : false;
        if (! $certificate || ! openssl_x509_check_private_key($certificate, $privateKeyPem)) {
            return $this->fail($device, 'ZIMRA returned a certificate that does not match the request, so it was not stored. Check the device, and register it again if ZIMRA now rejects it.');
        }

        $expiresAt = Carbon::createFromTimestampUTC(openssl_x509_parse($certificate)['validTo_time_t']);

        $device->update([
            'certificate_data' => $certificatePem,
            'private_key_data' => $privateKeyPem,
            'certificate_expires_at' => $expiresAt,
        ]);

        Log::info('ZIMRA device certificate renewed', [
            'device_id' => (string) $device->device_id,
            'expires_at' => $expiresAt->toDateTimeString(),
        ]);

        return ['outcome' => self::RENEWED, 'message' => 'Certificate renewed, valid until '.$expiresAt->toDateTimeString().' UTC.'];
    }

    /**
     * Generate an RSA-2048 CSR per ZIMRA spec: CN = ZIMRA-{serial}-{deviceId
     * left-padded to 10 digits}, C=ZW.
     *
     * @return array{0: string, 1: string} [csrPem, privateKeyPem]
     */
    public static function generateCsr(string $deviceId, string $serialNo): array
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

    private function expiryFromCertificate(ZimraDevice $device): ?Carbon
    {
        $parsed = $device->certificate_data ? openssl_x509_parse((string) $device->certificate_data) : false;

        return $parsed ? Carbon::createFromTimestampUTC($parsed['validTo_time_t']) : null;
    }

    /**
     * Try again on the next run, raising the alarm once expiry is close.
     *
     * @return array{outcome: string, message: string}
     */
    private function holdOff(ZimraDevice $device, ?Carbon $expiresAt, string $reason): array
    {
        if ($expiresAt && $expiresAt->lte(now()->addDays(self::URGENT_WITHIN_DAYS))) {
            $this->alert($device, 'Expires '.$expiresAt->toDateTimeString().' UTC and could not be renewed. '.$reason);
        }

        return ['outcome' => self::PENDING, 'message' => $reason];
    }

    /**
     * @return array{outcome: string, message: string}
     */
    private function fail(ZimraDevice $device, string $message): array
    {
        $this->alert($device, $message);

        return ['outcome' => self::FAILED, 'message' => $message];
    }

    private function alert(ZimraDevice $device, string $message): void
    {
        Log::error('ZIMRA device certificate alert', [
            'device_id' => (string) $device->device_id,
            'message' => $message,
        ]);

        $recipients = config('zimra.failure_alert_emails', []);
        if ($recipients === []) {
            return;
        }

        try {
            Mail::raw(
                "ZIMRA device {$device->device_id} certificate.\n\n{$message}",
                fn ($mail) => $mail->to($recipients)->subject('SmartPOS: a ZIMRA device certificate needs attention')
            );
        } catch (Throwable $e) {
            Log::error('ZIMRA certificate alert email failed', ['error' => $e->getMessage()]);
        }
    }
}
