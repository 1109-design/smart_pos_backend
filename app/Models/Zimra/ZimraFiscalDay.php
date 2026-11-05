<?php

namespace App\Models\Zimra;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The audit record of one fiscal day on one device: the counters declared to
 * ZIMRA at close, ZIMRA's replies, and whether the close was confirmed.
 */
class ZimraFiscalDay extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSE_SUBMITTED = 'close_submitted';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CLOSE_FAILED = 'close_failed';

    /** Stored as closing_error_code: only the Public Portal or ZIMRA can close this day. */
    public const MANUAL_CLOSE_REQUIRED = 'ManualCloseRequired';

    protected $fillable = [
        'business_id',
        'device_id',
        'fiscal_day_no',
        'status',
        'opened_at',
        'close_submitted_at',
        'closed_at',
        'receipt_counter',
        'last_receipt_global_no',
        'close_counters',
        'close_payload',
        'close_response',
        'close_status_response',
        'close_attempts',
        'closing_error_code',
        'server_signature',
        'error_message',
        'alerted_at',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_day_no' => 'integer',
            'opened_at' => 'datetime',
            'close_submitted_at' => 'datetime',
            'closed_at' => 'datetime',
            'close_counters' => 'array',
            'close_payload' => 'array',
            'close_response' => 'array',
            'close_status_response' => 'array',
            'close_attempts' => 'array',
            'alerted_at' => 'datetime',
        ];
    }

    /**
     * The receipts fiscalised in this day.
     */
    public function sales(): HasMany
    {
        return $this->hasMany(ZimraSale::class, 'fiscal_day_no', 'fiscal_day_no')
            ->where('device_id', $this->device_id);
    }

    /**
     * Days whose close hasn't been confirmed by ZIMRA.
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where(fn (Builder $days) => $days
            ->whereIn('status', [self::STATUS_CLOSE_SUBMITTED, self::STATUS_CLOSE_FAILED])
            ->orWhere('closing_error_code', self::MANUAL_CLOSE_REQUIRED));
    }

    /**
     * The fiscal day a GetStatus response refers to, or 0 when it names none.
     *
     * @param  array<string, mixed>  $status
     */
    public static function dayNoFromStatus(array $status): int
    {
        return (int) ($status['fiscalDayNo'] ?? $status['lastFiscalDayNo'] ?? 0);
    }

    /**
     * Find this day's record, creating it the first time the day is seen.
     */
    public static function recordFor(ZimraDevice $device, int $fiscalDayNo): self
    {
        return self::firstOrCreate(
            ['device_id' => (string) $device->device_id, 'fiscal_day_no' => $fiscalDayNo],
            [
                'business_id' => $device->business_id,
                'status' => self::STATUS_OPEN,
                'opened_at' => $device->fiscal_day_opened_at,
            ]
        );
    }

    public function requiresManualClose(): bool
    {
        return $this->closing_error_code === self::MANUAL_CLOSE_REQUIRED;
    }

    public function appendCloseAttempt(array $entry): void
    {
        $attempts = $this->close_attempts ?? [];
        $attempts[] = ['at' => now()->toIso8601String()] + $entry;
        $this->update(['close_attempts' => $attempts]);
    }
}
