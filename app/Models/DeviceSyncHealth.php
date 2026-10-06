<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** See the create_device_sync_diagnostics_tables migration. */
class DeviceSyncHealth extends Model
{
    protected $table = 'device_sync_health';

    protected $fillable = [
        'device_id',
        'business_id',
        'app_version',
        'platform',
        'current_user',
        'device_time',
        'clock_skew_seconds',
        'last_sync_at',
        'last_successful_sync_at',
        'snapshot',
        'reported_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'device_time' => 'datetime',
        'last_sync_at' => 'datetime',
        'last_successful_sync_at' => 'datetime',
        'reported_at' => 'datetime',
    ];
}
