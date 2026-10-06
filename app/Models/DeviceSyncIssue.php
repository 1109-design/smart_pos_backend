<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** See the create_device_sync_diagnostics_tables migration. */
class DeviceSyncIssue extends Model
{
    protected $fillable = [
        'business_id',
        'device_id',
        'fingerprint',
        'source',
        'category',
        'table_name',
        'message',
        'record_uuids',
        'record_uuid_count',
        'sample_payload',
        'details',
        'stack',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'app_version',
        'resolved_at',
        'resolution_note',
    ];

    protected $casts = [
        'record_uuids' => 'array',
        'sample_payload' => 'array',
        'details' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
