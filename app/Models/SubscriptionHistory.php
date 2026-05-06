<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionHistory extends Model
{
    protected $table = 'subscription_history';

    protected $fillable = [
        'tenant_id',
        'tier',
        'event_type',
        'previous_tier',
        'subscription_valid_until',
        'webhook_event_id',
        'metadata',
    ];

    protected $casts = [
        'subscription_valid_until' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
