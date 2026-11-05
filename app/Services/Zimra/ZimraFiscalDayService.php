<?php

namespace App\Services\Zimra;

use App\Jobs\ProcessZimraFiscalisationJob;
use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraFiscalDay;
use App\Models\Zimra\ZimraSale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Closes fiscal days and records the outcome. ZIMRA accepting a CloseDay request
 * only starts the close: it validates afterwards and can still reject it, so a
 * day counts as closed only once GetStatus says so. Until then the device keeps
 * fiscal_day_opened_at, which a retried close needs to total the right receipts.
 */
class ZimraFiscalDayService
{
    public const CLOSED = 'closed';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public const BLOCKED = 'blocked';

    private const STATUS_CLOSED = 'FiscalDayClosed';

    private const STATUS_CLOSE_INITIATED = 'FiscalDayCloseInitiated';

    private const STATUS_CLOSE_FAILED = 'FiscalDayCloseFailed';

    /** A close ZIMRA rejects for another reason gets this many sends in all. */
    private const MAX_CLOSE_ATTEMPTS = 3;

    private const CONFIRM_ATTEMPTS = 3;

    private const CONFIRM_WAIT_SECONDS = 5;

    /** How long a close may stay unconfirmed before someone is told. */
    private const UNCONFIRMED_ALERT_HOURS = 2;

    public function __construct(
        private ZimraClient $client,
        private ZimraCloseDayPayloadBuilder $builder,
        private FiscalisationGuard $guard,
        private ZimraReceiptPreflightService $preflight,
    ) {}

    /**
     * Get a held device moving, or flag it for the Public Portal. Makes no ZIMRA
     * call for a device with nothing wrong and nothing waiting.
     *
     * @return array{outcome: string, message: string}
     */
    public function reprobe(ZimraDevice $device): array
    {
        if ($reason = $this->guard->blockReason()) {
            return ['outcome' => self::BLOCKED, 'message' => $reason];
        }

        if (! $this->needsReprobe($device)) {
            return ['outcome' => self::CLOSED, 'message' => 'Nothing to recover.'];
        }

        $status = $this->client->getStatus((string) $device->device_id, $device);
        if (! $status['success']) {
            return ['outcome' => self::PENDING, 'message' => 'GetStatus failed: '.$status['error']];
        }

        $statusData = $status['data'];
        $fiscalDayStatus = $statusData['fiscalDayStatus'] ?? null;

        // Closed, including in the Public Portal. The first send reopens the day.
        if ($fiscalDayStatus === self::STATUS_CLOSED) {
            $this->settle($device, $statusData);
            ProcessZimraFiscalisationJob::dispatch((string) $device->device_id);

            return ['outcome' => self::CLOSED, 'message' => 'The fiscal day is closed; sending anything held.'];
        }

        $day = ZimraFiscalDay::recordFor($device, ZimraFiscalDay::dayNoFromStatus($statusData));

        if ($fiscalDayStatus === self::STATUS_CLOSE_FAILED) {
            if ($this->isValidationErrorClose($statusData['fiscalDayClosingErrorCode'] ?? null)) {
                return $this->markManualCloseRequired($device, $day, $statusData);
            }

            if (count($day->close_attempts ?? []) >= self::MAX_CLOSE_ATTEMPTS) {
                return $this->markManualCloseRequired(
                    $device,
                    $day,
                    $statusData,
                    'ZIMRA rejected the close '.self::MAX_CLOSE_ATTEMPTS.' times: '.($statusData['fiscalDayClosingErrorCode'] ?? 'no reason given').'.'
                );
            }

            return $this->submit($device, $statusData);
        }

        if ($fiscalDayStatus === self::STATUS_CLOSE_INITIATED) {
            return $this->settle($device, $statusData);
        }

        if ($this->preflight->dayHasRedReceipt($device)) {
            return $this->markManualCloseRequired($device, $day, $statusData);
        }

        if ($this->preflight->dayHasExpired($device)) {
            return $this->submit($device, $statusData);
        }

        ProcessZimraFiscalisationJob::dispatch((string) $device->device_id);

        return ['outcome' => self::PENDING, 'message' => 'Fiscal day is open; sending anything held.'];
    }

    private function needsReprobe(ZimraDevice $device): bool
    {
        return $this->unresolvedDays($device)->exists()
            || $this->preflight->dayHasRedReceipt($device)
            || $this->preflight->dayHasExpired($device)
            || ZimraSale::where('device_id', (string) $device->device_id)->whereIn('status', ['pending', 'retry'])->exists();
    }

    /**
     * ZIMRA's enum, which can arrive as its name or its number (spec §5.4).
     */
    private function isValidationErrorClose(mixed $closingError): bool
    {
        return $closingError === 2
            || $closingError === '2'
            || str_contains((string) $closingError, 'ReceiptsWithValidationErrors')
            || str_contains((string) $closingError, '"fiscalDayClosingErrorCode":2');
    }

    /**
     * ZIMRA refuses CloseDay outright for a day with a Red or Grey receipt, so
     * nothing here can close it. Say what to do, once, and stop trying.
     *
     * @param  array<string, mixed>|null  $statusData
     * @return array{outcome: string, message: string}
     */
    private function markManualCloseRequired(ZimraDevice $device, ZimraFiscalDay $day, ?array $statusData, ?string $detail = null): array
    {
        $alreadyFlagged = $day->requiresManualClose();
        $message = "Fiscal day {$day->fiscal_day_no} must be closed in the ZIMRA Public Portal, or by a ZIMRA officer. "
            .($detail ?? 'It has a receipt ZIMRA marked Red, so ZIMRA will not let it close normally.')
            .' Sales and invoices are held and will be sent once it is closed.';

        $day->update([
            'status' => $statusData !== null && ($statusData['fiscalDayStatus'] ?? null) === self::STATUS_CLOSE_FAILED
                ? ZimraFiscalDay::STATUS_CLOSE_FAILED
                : $day->status,
            'closing_error_code' => ZimraFiscalDay::MANUAL_CLOSE_REQUIRED,
            'close_status_response' => $statusData ?? $day->close_status_response,
            'error_message' => $message,
        ]);

        if (! $alreadyFlagged) {
            $this->alert($device, $day, 'A fiscal day must be closed in the ZIMRA Public Portal', $message, canRetry: false);
        }

        return ['outcome' => self::FAILED, 'message' => $message];
    }

    /**
     * Close the device's fiscal day, or retry a close ZIMRA rejected.
     *
     * @return array{outcome: string, message: string}
     */
    public function close(ZimraDevice $device): array
    {
        if ($reason = $this->guard->blockReason()) {
            return ['outcome' => self::BLOCKED, 'message' => $reason];
        }

        $status = $this->client->getStatus((string) $device->device_id, $device);
        if (! $status['success']) {
            $message = 'GetStatus failed: '.$status['error'];
            $this->alert($device, null, 'Could not start the fiscal day close', $message);

            return ['outcome' => self::FAILED, 'message' => $message];
        }

        $statusData = $status['data'];
        $fiscalDayStatus = $statusData['fiscalDayStatus'] ?? null;

        if ($fiscalDayStatus === self::STATUS_CLOSED) {
            $this->settle($device, $statusData);
            $device->update(['fiscal_day_opened_at' => null]);

            return ['outcome' => self::CLOSED, 'message' => 'Fiscal day is already closed.'];
        }

        if ($fiscalDayStatus === self::STATUS_CLOSE_INITIATED) {
            return $this->awaitConfirmation($device, $statusData);
        }

        if ($this->isValidationErrorClose($statusData['fiscalDayClosingErrorCode'] ?? null) || $this->preflight->dayHasRedReceipt($device)) {
            return $this->markManualCloseRequired(
                $device,
                ZimraFiscalDay::recordFor($device, ZimraFiscalDay::dayNoFromStatus($statusData)),
                $statusData
            );
        }

        return $this->submit($device, $statusData);
    }

    /**
     * Check any unconfirmed close against ZIMRA. Makes no ZIMRA call when the
     * device has none.
     *
     * @return array{outcome: string, message: string}
     */
    public function confirm(ZimraDevice $device): array
    {
        if (! $this->unresolvedDays($device)->exists()) {
            return ['outcome' => self::CLOSED, 'message' => 'No close is waiting for confirmation.'];
        }

        $status = $this->client->getStatus((string) $device->device_id, $device);
        if (! $status['success']) {
            return ['outcome' => self::PENDING, 'message' => 'GetStatus failed: '.$status['error']];
        }

        return $this->settle($device, $status['data']);
    }

    /**
     * @param  array<string, mixed>  $statusData
     * @return array{outcome: string, message: string}
     */
    private function submit(ZimraDevice $device, array $statusData): array
    {
        $fiscalDayNo = ZimraFiscalDay::dayNoFromStatus($statusData);
        if ($fiscalDayNo <= 0) {
            $message = 'ZIMRA did not report a fiscal day number, so the close was not sent.';
            $this->alert($device, null, 'Could not start the fiscal day close', $message);

            return ['outcome' => self::FAILED, 'message' => $message];
        }

        $day = ZimraFiscalDay::recordFor($device, $fiscalDayNo);

        // The builder totals receipts since this time, so a device that lost it
        // must get it back from the day's record to total the right receipts.
        if (! $device->fiscal_day_opened_at && $day->opened_at) {
            $device->update(['fiscal_day_opened_at' => $day->opened_at]);
        }

        $lastReceiptCounter = isset($statusData['lastReceiptCounter']) ? (int) $statusData['lastReceiptCounter'] : null;
        $payload = $this->builder->build($device, $fiscalDayNo, $lastReceiptCounter);

        $day->update([
            'receipt_counter' => $payload['receiptCounter'],
            'last_receipt_global_no' => $statusData['lastReceiptGlobalNo'] ?? null,
            'close_counters' => $payload['fiscalDayCounters'],
            'close_payload' => $payload,
            'alerted_at' => null,
        ]);

        $result = $this->client->closeFiscalDay((string) $device->device_id, $payload, $device);

        $day->appendCloseAttempt([
            'receipt_counter' => $payload['receiptCounter'],
            'counters' => $payload['fiscalDayCounters'],
            'success' => $result['success'],
            'response' => $result['success'] ? $result['data'] : $result['error'],
        ]);

        if (! $result['success']) {
            return $this->markFailed($device, $day, null, 'CloseDay failed: '.$result['error']);
        }

        $day->update([
            'status' => ZimraFiscalDay::STATUS_CLOSE_SUBMITTED,
            'close_submitted_at' => now(),
            'close_response' => $result['data'],
            'closing_error_code' => null,
            'error_message' => null,
        ]);

        return $this->awaitConfirmation($device);
    }

    /**
     * Give ZIMRA a moment to finish validating. A close still unresolved after
     * this is picked up by the scheduled confirm.
     *
     * @param  array<string, mixed>|null  $statusData  A status already fetched, to check first.
     * @return array{outcome: string, message: string}
     */
    private function awaitConfirmation(ZimraDevice $device, ?array $statusData = null): array
    {
        if ($statusData !== null) {
            $day = ZimraFiscalDay::recordFor($device, ZimraFiscalDay::dayNoFromStatus($statusData));
            $day->update([
                'status' => ZimraFiscalDay::STATUS_CLOSE_SUBMITTED,
                'close_submitted_at' => $day->close_submitted_at ?? now(),
            ]);
        }

        $result = ['outcome' => self::PENDING, 'message' => 'The close was sent; ZIMRA has not confirmed it yet.'];

        for ($attempt = 1; $attempt <= self::CONFIRM_ATTEMPTS; $attempt++) {
            Sleep::for(self::CONFIRM_WAIT_SECONDS)->seconds();

            $status = $this->client->getStatus((string) $device->device_id, $device);
            if (! $status['success']) {
                continue;
            }

            $result = $this->settle($device, $status['data']);
            if ($result['outcome'] !== self::PENDING) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * Apply a GetStatus response to the device's unconfirmed days.
     *
     * @param  array<string, mixed>  $statusData
     * @return array{outcome: string, message: string}
     */
    private function settle(ZimraDevice $device, array $statusData): array
    {
        $fiscalDayStatus = $statusData['fiscalDayStatus'] ?? null;
        $currentDayNo = ZimraFiscalDay::dayNoFromStatus($statusData);
        $result = ['outcome' => self::CLOSED, 'message' => 'No close is waiting for confirmation.'];

        foreach ($this->unresolvedDays($device)->orderBy('fiscal_day_no')->get() as $day) {
            // A later day number means this one closed and the next sale already
            // opened a new day, so its status can't be read from this response.
            $laterDayOpened = $currentDayNo > $day->fiscal_day_no;

            if ($fiscalDayStatus === self::STATUS_CLOSED || $laterDayOpened) {
                $result = $this->markClosed($day, $laterDayOpened ? null : $statusData);

                continue;
            }

            if ($fiscalDayStatus === self::STATUS_CLOSE_FAILED) {
                $result = $this->markFailed(
                    $device,
                    $day,
                    $statusData,
                    'ZIMRA rejected the close: '.($statusData['fiscalDayClosingErrorCode'] ?? 'no reason given')
                );

                continue;
            }

            // ZIMRA never took this close (CloseDay itself was refused), so the
            // day is simply still open and the failure stands until a retry.
            if ($day->status === ZimraFiscalDay::STATUS_CLOSE_FAILED || $day->requiresManualClose()) {
                $result = ['outcome' => self::FAILED, 'message' => (string) $day->error_message];

                continue;
            }

            $result = ['outcome' => self::PENDING, 'message' => 'The close was sent; ZIMRA has not confirmed it yet.'];
            $this->alertIfUnconfirmedTooLong($device, $day);
        }

        if ($fiscalDayStatus === self::STATUS_CLOSED) {
            $device->update(['fiscal_day_opened_at' => null]);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $statusData  Null when the response is about a later day.
     * @return array{outcome: string, message: string}
     */
    private function markClosed(ZimraFiscalDay $day, ?array $statusData): array
    {
        $signature = $statusData['fiscalDayServerSignature'] ?? null;
        $closedAt = $statusData['fiscalDayClosed'] ?? null;

        $day->update([
            'status' => ZimraFiscalDay::STATUS_CLOSED,
            'closed_at' => $closedAt ? Carbon::parse($closedAt, 'Africa/Harare')->utc() : now(),
            'server_signature' => is_array($signature) ? ($signature['signature'] ?? null) : $signature,
            'close_status_response' => $statusData ?? $day->close_status_response,
            'closing_error_code' => null,
            'error_message' => null,
        ]);

        return ['outcome' => self::CLOSED, 'message' => "Fiscal day {$day->fiscal_day_no} closed and confirmed by ZIMRA."];
    }

    /**
     * @param  array<string, mixed>|null  $statusData
     * @return array{outcome: string, message: string}
     */
    private function markFailed(ZimraDevice $device, ZimraFiscalDay $day, ?array $statusData, string $message): array
    {
        if ($this->isValidationErrorClose($statusData['fiscalDayClosingErrorCode'] ?? $message)) {
            return $this->markManualCloseRequired($device, $day, $statusData);
        }

        $day->update([
            'status' => ZimraFiscalDay::STATUS_CLOSE_FAILED,
            'closing_error_code' => $statusData['fiscalDayClosingErrorCode'] ?? null,
            'close_status_response' => $statusData ?? $day->close_status_response,
            'error_message' => $message,
        ]);

        // The scheduled confirm sees the same failure every run: tell people once
        // per attempt, not every 15 minutes. A new attempt clears alerted_at.
        if (! $day->alerted_at) {
            $this->alert($device, $day, 'A fiscal day close failed', $message);
        }

        return ['outcome' => self::FAILED, 'message' => $message];
    }

    private function alertIfUnconfirmedTooLong(ZimraDevice $device, ZimraFiscalDay $day): void
    {
        $tooLong = $day->close_submitted_at
            && $day->close_submitted_at->lte(now()->subHours(self::UNCONFIRMED_ALERT_HOURS));

        if ($tooLong && ! $day->alerted_at) {
            $this->alert(
                $device,
                $day,
                'A fiscal day close has not been confirmed',
                'ZIMRA accepted the close at '.$day->close_submitted_at->toDateTimeString().' UTC but has not confirmed it.'
            );
        }
    }

    private function unresolvedDays(ZimraDevice $device)
    {
        return ZimraFiscalDay::where('device_id', (string) $device->device_id)->unresolved();
    }

    private function alert(ZimraDevice $device, ?ZimraFiscalDay $day, string $subject, string $message, bool $canRetry = true): void
    {
        $dayText = $day ? "fiscal day {$day->fiscal_day_no}" : 'current fiscal day';
        $body = "ZIMRA device {$device->device_id}, {$dayText}.\n\n{$message}"
            .($canRetry ? "\n\nTo retry, run: php artisan zimra:fiscal-day close {$device->device_id}" : '');

        Log::error('ZIMRA fiscal day alert: '.$subject, [
            'device_id' => (string) $device->device_id,
            'fiscal_day_no' => $day?->fiscal_day_no,
            'message' => $message,
        ]);

        $day?->update(['alerted_at' => now()]);

        $recipients = config('zimra.failure_alert_emails', []);
        if ($recipients === []) {
            return;
        }

        try {
            Mail::raw($body, fn ($mail) => $mail->to($recipients)->subject('SmartPOS: '.$subject));
        } catch (Throwable $e) {
            // The log entry above still records it; a mail outage mustn't stop
            // the close being recorded.
            Log::error('ZIMRA fiscal day alert email failed', ['error' => $e->getMessage()]);
        }
    }
}
