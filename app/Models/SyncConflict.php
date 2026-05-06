<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncConflict extends Model
{
    protected $fillable = [
        'business_id',
        'table_name',
        'record_uuid',
        'device_id',
        'reason',
        'conflict_type',
        'local_payload',
        'server_payload',
        'status',
        'resolved_by',
        'resolution_action',
        'resolved_at',
    ];

    protected $casts = [
        'local_payload' => 'array',
        'server_payload' => 'array',
        'resolved_at' => 'datetime',
    ];
}
