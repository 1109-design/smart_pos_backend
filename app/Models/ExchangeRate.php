<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasUuids;


    protected $fillable = [
        'id', 'from_currency', 'to_currency', 'rate', 'source',
        'set_by_user_id', 'locked', 'valid_from', 'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'rate'       => 'decimal:8',
            'locked'     => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }
}
