<?php

namespace App\Models\Zimra;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One contact with ZIMRA's device API. Written only by
 * ZimraDeviceEventRecorder, which never stores payloads, certificates or keys.
 */
class ZimraDeviceEvent extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = [
        'business_id',
        'device_id',
        'kind',
        'source',
        'success',
        'guard_blocked',
        'fiscal_day_no',
        'http_status',
        'duration_ms',
        'summary',
        'error',
        'user_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'guard_blocked' => 'boolean',
            'fiscal_day_no' => 'integer',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::where('occurred_at', '<', now()->subDays((int) config('zimra.event_retention_days', 90)));
    }
}
