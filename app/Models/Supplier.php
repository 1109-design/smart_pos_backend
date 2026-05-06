<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasUuids;


    protected $fillable = [
        'id', 'business_id', 'name', 'contact_name', 'phone', 'email',
        'address', 'website', 'notes', 'tax_number', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
