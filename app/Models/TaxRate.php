<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use HasUuids;


    protected $fillable = [
        'id', 'business_id', 'name', 'rate', 'type', 'is_compound', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate'        => 'decimal:4',
            'is_compound' => 'boolean',
            'is_default'  => 'boolean',
            'is_active'   => 'boolean',
        ];
    }
}
