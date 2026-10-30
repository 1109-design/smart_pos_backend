<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** See the create_audit_entries_table migration. Append-only. */
class AuditEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entry_id',
        'business_id',
        'device_id',
        'device_name',
        'seq',
        'occurred_at',
        'received_at',
        'kind',
        'action',
        'category',
        'important',
        'table_name',
        'record_id',
        'summary',
        'details',
        'actor',
        'user_id',
        'user_name',
        'user_role',
        'location_id',
        'till_id',
        'app_version',
    ];

    protected $casts = [
        'details' => 'array',
        'important' => 'boolean',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];
}
