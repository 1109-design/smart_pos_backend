<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'period_start', 'period_end', 'status',
        'closed_at', 'closed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Absent a period row covering a date, posting is allowed — an owner
     * opts INTO locking a period, closed periods aren't the default. Only
     * an explicit closed row blocks a post.
     *
     * Compares via SQL DATE(period_start)/DATE(period_end), not a plain
     * column comparison — the columns are stored with a "00:00:00" time
     * part, so a boundary date (transDate exactly equal to period_start or
     * period_end) compares as string > date-only and would be wrongly
     * excluded. MySQL's native DATE type masks this; SQLite doesn't, which
     * is how the same bug was caught in PartyLedgerService.
     */
    public static function isClosedFor(string $businessId, string $transDate): bool
    {
        return static::where('business_id', $businessId)
            ->whereRaw('DATE(period_start) <= ?', [$transDate])
            ->whereRaw('DATE(period_end) >= ?', [$transDate])
            ->where('status', 'closed')
            ->exists();
    }
}
