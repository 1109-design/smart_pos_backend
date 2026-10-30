<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Pending Book entry synced from the tills — see the
 * create_pending_book_tables migration.
 */
class PendingCollection extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'transaction_id', 'location_id',
        'collector_name', 'collector_phone', 'collector_id_number',
        'vehicle_registration', 'status', 'notes', 'items_json',
        'expected_collection_date', 'reminder_days_before', 'reminder_sent',
        'otp_code', 'otp_sent_at', 'otp_expires_at', 'confirmed_by_user_id',
        'collected_at', 'cancelled_by_user_id', 'cancellation_reason',
        'cancelled_at', 'sms_message_id', 'reversal_count', 'last_reversed_at',
        'version', 'device_id', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_collection_date' => 'datetime',
            'otp_sent_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'collected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_reversed_at' => 'datetime',
            'reminder_sent' => 'boolean',
            'reminder_days_before' => 'integer',
            'reversal_count' => 'integer',
            'version' => 'integer',
        ];
    }

    public const STATUSES = [
        'awaiting_stock', 'pending', 'otp_sent', 'partially_collected',
        'reversal_pending', 'collected', 'cancelled',
    ];

    /**
     * Guards only what can never happen, whatever the path: a device's push
     * queue keeps just the latest state per entry, so several local steps
     * (stock arrives → OTP sent → collected) can reach the server as one
     * jump. What's forbidden is reopening a cancelled entry, or a collected
     * one going anywhere but back through an approved reversal.
     */
    public static function isValidTransition(?string $from, string $to): bool
    {
        if (! in_array($to, self::STATUSES, true)) {
            return false;
        }

        return match ($from) {
            null => true,
            'cancelled' => $to === 'cancelled',
            'collected' => in_array($to, ['collected', 'reversal_pending', 'otp_sent', 'partially_collected'], true),
            default => true,
        };
    }

    public function events(): HasMany
    {
        return $this->hasMany(PendingCollectionEvent::class, 'collection_id');
    }
}
