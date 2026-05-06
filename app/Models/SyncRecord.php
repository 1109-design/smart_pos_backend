<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRecord extends Model
{
    protected $fillable = [
        'business_id',
        'table_name',
        'record_uuid',
        'operation',
        'payload',
        'source_updated_at',
        'synced_at',
        'device_id',
        'conflict_resolved',
    ];

    protected $casts = [
        'payload' => 'array',
        'source_updated_at' => 'datetime',
        'synced_at' => 'datetime',
        'conflict_resolved' => 'boolean',
    ];
}
