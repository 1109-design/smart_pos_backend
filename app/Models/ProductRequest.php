<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRequest extends Model
{
    use HasUuids;

    // No updated_at column — each ask is an immutable log entry aside from
    // its status, and created_at is set explicitly from the device payload.
    public $timestamps = false;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'requested_by_user_id',
        'product_name', 'note', 'status', 'created_at', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
