<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
        'tin',
        'currency_code',
        'logo_path',
        'metadata',
        'fiscalisation_enabled',
        'stock_reset_at',
        'stock_reset_by_user_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'fiscalisation_enabled' => 'boolean',
        'stock_reset_at' => 'datetime',
    ];
}
