<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PoAuditLog extends Model
{

    protected $table = 'po_audit_logs';

    const UPDATED_AT = null;

    protected $fillable = [
        'po_id', 'user_id', 'user_name', 'action', 'note', 'snapshot_json',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_json' => 'array',
        ];
    }
}
