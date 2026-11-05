<?php

namespace App\Services\Zimra;

use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraSale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The last check before a receipt is signed and sent. Anything unsafe holds the
 * receipt here instead of creating a Red one, because a single Red receipt stops
 * ZIMRA closing the whole fiscal day and a global number, once issued, is never
 * freed. Issued numbers and receipts are never edited to get past a hold.
 */
class ZimraReceiptPreflightService
{
    public const DAY_NOT_OPEN = 'DAY_NOT_OPEN';

    public const DAY_EXPIRED = 'DAY_EXPIRED';

    public const RED_RECEIPT = 'RED_RECEIPT';

    public const STATUS_LAG = 'STATUS_LAG';

    public const CHAIN_GAP = 'CHAIN_GAP';

    public const HASH_MISMATCH = 'HASH_MISMATCH';

    public const SIGNING_KEY_INVALID = 'SIGNING_KEY_INVALID';

    public const NO_TAX_CONFIG = 'NO_TAX_CONFIG';

    /**
     * @param  array<string, mixed>  $statusData  A GetStatus response taken under the device lock.
     * @return array{safe: bool, reason: string|null, message: string|null, next_global_no: int|null, next_counter: int|null, previous_hash: string|null}
     */
    public function evaluate(ZimraDevice $device, array $statusData): array
    {
        $lastCounter = isset($statusData['lastReceiptCounter']) ? (int) $statusData['lastReceiptCounter'] : null;

        $dayIsOpen = strcasecmp((string) ($statusData['fiscalDayStatus'] ?? ''), 'FiscalDayOpened') === 0;
        if ($dayIsOpen) {
            $this->startDayHereIfZimraHasNoReceipts($device, $lastCounter);
        }

        if (! $this->hasUsableKey($device)) {
            return $this->hold(self::SIGNING_KEY_INVALID);
        }
        if (empty($device->applicable_taxes)) {
            return $this->hold(self::NO_TAX_CONFIG);
        }
        if (! $dayIsOpen) {
            return $this->hold(self::DAY_NOT_OPEN);
        }
        if ($this->dayHasExpired($device)) {
            return $this->hold(self::DAY_EXPIRED);
        }

        $rows = $this->currentDayReceipts($device);

        if ($rows->contains(fn (ZimraSale $row) => $row->isRejectedByZimra())) {
            return $this->hold(self::RED_RECEIPT);
        }

        $globals = $rows->pluck('receipt_global_no')->map(fn ($no) => (int) $no)->sort()->values();
        $localMax = $globals->last();
        $zimraLast = isset($statusData['lastReceiptGlobalNo']) ? (int) $statusData['lastReceiptGlobalNo'] : null;

        // ZIMRA's status can lag behind a receipt it has already taken. Sending
        // now would reuse that receipt's number (RCPT012).
        if ($localMax !== null && $zimraLast !== null && $localMax > $zimraLast) {
            return $this->hold(self::STATUS_LAG);
        }

        // Only today's chain is checked: older days are finished, and ZIMRA holds
        // their numbers whether or not a local row still records them.
        if ($globals->isNotEmpty() && $globals->last() - $globals->first() + 1 !== $globals->count()) {
            return $this->hold(self::CHAIN_GAP);
        }

        $nextGlobal = ($zimraLast ?? $localMax ?? 0) + 1;
        $nextCounter = $lastCounter !== null ? $lastCounter + 1 : $rows->count() + 1;

        // The hash chain restarts each day. Any later receipt must chain off the
        // one directly before it, or it's a guaranteed RCPT020.
        $previousHash = null;
        if ($nextCounter > 1) {
            $previous = $rows->first(fn (ZimraSale $row) => (int) $row->receipt_global_no === $nextGlobal - 1);
            $previousHash = $previous?->device_signature_hash;

            if (empty($previousHash)) {
                return $this->hold(self::HASH_MISMATCH);
            }
        }

        return [
            'safe' => true,
            'reason' => null,
            'message' => null,
            'next_global_no' => $nextGlobal,
            'next_counter' => $nextCounter,
            'previous_hash' => $previousHash,
        ];
    }

    public function dayHasRedReceipt(ZimraDevice $device): bool
    {
        return $this->currentDayReceipts($device)->contains(fn (ZimraSale $row) => $row->isRejectedByZimra());
    }

    public function dayHasExpired(ZimraDevice $device): bool
    {
        return $device->fiscal_day_opened_at
            && $device->fiscal_day_max_hours
            && now()->gte($device->fiscal_day_opened_at->copy()->addHours((int) $device->fiscal_day_max_hours));
    }

    /**
     * @return Collection<int, ZimraSale>
     */
    private function currentDayReceipts(ZimraDevice $device): Collection
    {
        if (! $device->fiscal_day_opened_at) {
            return new Collection;
        }

        return ZimraSale::where('device_id', (string) $device->device_id)
            ->whereIn('status', ZimraSale::IN_CHAIN_STATUSES)
            ->whereNotNull('receipt_global_no')
            ->whereNotNull('fiscalised_at')
            ->where('fiscalised_at', '>=', $device->fiscal_day_opened_at)
            ->get();
    }

    /**
     * ZIMRA says this day has no receipts, as after a day closed in the Public
     * Portal and reopened, so any rows still inside the local window belong to an
     * earlier day. Left in place they'd supply a wrong hash, or a stale Red hold.
     */
    private function startDayHereIfZimraHasNoReceipts(ZimraDevice $device, ?int $lastCounter): void
    {
        if ($lastCounter !== 0) {
            return;
        }

        if (! $device->fiscal_day_opened_at || $this->currentDayReceipts($device)->isNotEmpty()) {
            Log::warning('ZIMRA fiscal day boundary moved: ZIMRA reports no receipts in this day', [
                'device_id' => (string) $device->device_id,
                'previous_boundary' => $device->fiscal_day_opened_at?->toDateTimeString(),
            ]);
            $device->update(['fiscal_day_opened_at' => now()]);
        }
    }

    private function hasUsableKey(ZimraDevice $device): bool
    {
        return $device->private_key_data && openssl_pkey_get_private((string) $device->private_key_data) !== false;
    }

    /**
     * @return array{safe: bool, reason: string, message: string, next_global_no: null, next_counter: null, previous_hash: null}
     */
    private function hold(string $reason): array
    {
        return [
            'safe' => false,
            'reason' => $reason,
            'message' => match ($reason) {
                self::RED_RECEIPT => 'Held: this fiscal day has a receipt ZIMRA rejected. Close the day in the ZIMRA Public Portal to resume.',
                self::DAY_NOT_OPEN => 'Held: the fiscal day is not open at ZIMRA.',
                self::DAY_EXPIRED => 'Held: the fiscal day has passed its time limit and needs closing.',
                self::STATUS_LAG => 'Held: ZIMRA has not caught up with the last receipt yet. Retrying automatically.',
                self::CHAIN_GAP => 'Held: a receipt is missing from today\'s chain, so sending could be rejected.',
                self::HASH_MISMATCH => 'Held: the previous receipt in today\'s chain can\'t be matched, so sending would be rejected.',
                self::SIGNING_KEY_INVALID => 'Held: the fiscal device\'s signing key is missing or invalid.',
                self::NO_TAX_CONFIG => 'Held: the fiscal device has no tax setup from ZIMRA yet.',
            },
            'next_global_no' => null,
            'next_counter' => null,
            'previous_hash' => null,
        ];
    }
}
