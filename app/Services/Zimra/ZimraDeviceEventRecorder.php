<?php

namespace App\Services\Zimra;

use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraDeviceEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the device log (zimra_device_events) and the device's last-contact
 * columns. Only an outcome is kept: the error text and a few whitelisted
 * fields of ZIMRA's answer — never a payload, receipt, certificate or key.
 * Logging must never break a fiscal call, so every failure here is swallowed.
 */
class ZimraDeviceEventRecorder
{
    /** Fields of ZIMRA's answer worth showing; nothing else is stored. */
    private const SUMMARY_KEYS = [
        'fiscalDayStatus', 'fiscalDayNo', 'lastFiscalDayNo', 'lastReceiptGlobalNo',
        'reportingFrequency', 'receiptID', 'receiptGlobalNo', 'operationID',
    ];

    /**
     * @param  array{success?: bool, data?: mixed, error?: string, status_code?: int, guard_blocked?: bool}  $result
     * @param  array{fiscal_day_no?: int|null, source?: string|null, user_id?: string|null}  $context
     */
    public function record(ZimraDevice $device, string $kind, array $result, ?int $durationMs = null, array $context = []): void
    {
        try {
            $success = (bool) ($result['success'] ?? false);
            $blocked = (bool) ($result['guard_blocked'] ?? false);
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $now = now();

            ZimraDeviceEvent::create([
                'business_id' => (string) $device->business_id,
                'device_id' => (string) $device->device_id,
                'kind' => $kind,
                'source' => $context['source'] ?? null,
                'success' => $success,
                'guard_blocked' => $blocked,
                'fiscal_day_no' => $context['fiscal_day_no'] ?? $this->dayNo($data),
                'http_status' => $result['status_code'] ?? null,
                'duration_ms' => $durationMs,
                'summary' => $this->summary($data),
                'error' => $success ? null : Str::limit($this->scrub((string) ($result['error'] ?? 'Unknown error')), 1000),
                'user_id' => $context['user_id'] ?? null,
                'occurred_at' => $now,
            ]);

            // A blocked call never reached ZIMRA, so it says nothing about the
            // connection.
            if ($blocked) {
                return;
            }
            $device->forceFill($success
                ? ['last_contact_ok_at' => $now]
                : [
                    'last_contact_failed_at' => $now,
                    'last_contact_error' => Str::limit($this->scrub((string) ($result['error'] ?? 'Unknown error')), 1000),
                ])->saveQuietly();
        } catch (Throwable $e) {
            Log::warning('ZIMRA device event could not be recorded', [
                'device_id' => $device->device_id,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dayNo(array $data): ?int
    {
        $no = $data['fiscalDayNo'] ?? $data['lastFiscalDayNo'] ?? null;

        return is_numeric($no) ? (int) $no : null;
    }

    private function summary(array $data): ?string
    {
        $parts = [];
        foreach (self::SUMMARY_KEYS as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $parts[] = $key.'='.$data[$key];
            }
        }

        return $parts === [] ? null : Str::limit(implode(', ', $parts), 250);
    }

    /** Strips anything that looks like PEM material from an error message. */
    private function scrub(string $text): string
    {
        return (string) preg_replace('/-----BEGIN [A-Z ]+-----.*?(-----END [A-Z ]+-----|$)/s', '[redacted]', $text);
    }
}
