<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncCursor extends Model
{
    protected $fillable = [
        'device_id',
        'table_name',
        'last_pulled_at',
        'last_pulled_id',
    ];

    protected $casts = [
        'last_pulled_at' => 'datetime',
    ];
}
