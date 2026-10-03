<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRequest extends Model
{
    use HasUuids;

    // created_at/updated_at are set explicitly from the device payload
    // (the till owns these timestamps, not the server clock) rather than
    // through Eloquent's automatic timestamp management.
    public $timestamps = false;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'product_id', 'requested_by_user_id',
        'product_name', 'customer_name', 'customer_phone', 'quantity', 'note',
        'status', 'stock_available_at', 'notified_by_user_id', 'notified_at',
        'sms_message_id', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'stock_available_at' => 'datetime',
            'notified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
