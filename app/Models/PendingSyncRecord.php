<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingSyncRecord extends Model
{
    protected $fillable = [
        'business_id',
        'device_id',
        'acting_user_id',
        'table_name',
        'record_uuid',
        'operation',
        'payload',
        'source_updated_at',
        'attempts',
        'failed_permanently',
        'last_error',
        'last_attempt_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'source_updated_at' => 'datetime',
        'attempts' => 'integer',
        'failed_permanently' => 'boolean',
        'last_attempt_at' => 'datetime',
    ];
}
