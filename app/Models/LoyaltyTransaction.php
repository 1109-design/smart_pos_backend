<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    use HasUuids;


    protected $fillable = [
        'id', 'customer_id', 'transaction_id', 'points', 'type', 'note',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:4',
        ];
    }
}
