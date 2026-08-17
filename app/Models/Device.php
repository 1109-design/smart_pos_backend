<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'device_identifier',
        'location_id',
        'token_id',
        'last_seen_at',
        'is_revoked',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'is_revoked' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** The shop/warehouse this device operates from, if assigned. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
