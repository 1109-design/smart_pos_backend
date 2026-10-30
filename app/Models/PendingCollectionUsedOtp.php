<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every Pending Book OTP ever issued in a business. A code is issued once,
 * ever — unique on (business_id, otp).
 */
class PendingCollectionUsedOtp extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'business_id', 'otp', 'collection_id', 'issued_at'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }
}
