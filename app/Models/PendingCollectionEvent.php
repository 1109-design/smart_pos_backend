<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only history of a Pending Book entry. Never updated or deleted. */
class PendingCollectionEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'collection_id', 'business_id', 'event_type', 'from_status',
        'to_status', 'actor_user_id', 'approver_user_id', 'approval_request_id',
        'reason', 'data_json', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(PendingCollection::class, 'collection_id');
    }
}
