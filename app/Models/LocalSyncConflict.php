<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalSyncConflict extends Model
{
    protected $fillable = [
        'business_id',
        'device_id',
        'table_name',
        'record_uuid',
        'reason',
        'local_payload',
        'incoming_payload',
        'peer_device_name',
        'status',
        'occurred_at',
    ];

    protected $casts = [
        'local_payload' => 'array',
        'incoming_payload' => 'array',
        'occurred_at' => 'datetime',
    ];
}
