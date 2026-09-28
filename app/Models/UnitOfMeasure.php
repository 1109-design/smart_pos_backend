<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UnitOfMeasure extends Model
{
    use HasUuids;

    protected $table = 'units_of_measure';

    protected $fillable = [
        'id', 'business_id', 'name', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
