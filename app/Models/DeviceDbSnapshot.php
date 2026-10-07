<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** See the create_device_db_snapshots_table migration. */
class DeviceDbSnapshot extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const OPEN_STATUSES = [self::STATUS_REQUESTED, self::STATUS_UPLOADING];

    protected $fillable = [
        'business_id',
        'device_id',
        'requested_by_device_id',
        'status',
        'chunks_total',
        'size_bytes',
        'sha256',
        'app_version',
        'schema_version',
        'path',
        'error',
        'requested_at',
        'completed_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Folder on the local (private) disk holding this snapshot's files. */
    public function directory(): string
    {
        return "db-snapshots/{$this->business_id}/{$this->id}";
    }
}
