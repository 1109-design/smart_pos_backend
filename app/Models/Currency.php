<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{

    public $timestamps = false;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code', 'name', 'symbol', 'decimal_places', 'is_base', 'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_base'    => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }
}
