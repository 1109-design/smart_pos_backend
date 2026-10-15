<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One change to master data or a financial document (Phase 12.2), written
 * on the device that made it. Append-only: never updated or deleted.
 */
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id', 'business_id', 'user_id', 'device_id', 'model', 'record_id',
        'action', 'before', 'after', 'at',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'at' => 'datetime'];
    }
}
