<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Business extends Model
{
    use HasUuids;


    protected $fillable = [
        'id',
        'name',
        'address',
        'phone',
        'email',
        'tax_number',
        'currency_code',
        'logo_path',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
