<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tier extends Model
{
    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = ['key', 'label', 'price', 'features', 'sort_order'];

    protected $casts = [
        'features'   => 'array',
        'sort_order' => 'integer',
    ];
}
