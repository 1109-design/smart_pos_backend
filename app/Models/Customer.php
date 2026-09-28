<?php

namespace App\Models;

use App\Events\CustomerChanged;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'name', 'phone', 'email', 'address', 'photo_path',
        'loyalty_points', 'credit_balance', 'credit_limit', 'is_tax_exempt', 'group',
    ];

    protected static function booted(): void
    {
        $dispatch = function (Customer $customer): void {
            if (! $customer->business_id) {
                return;
            }

            CustomerChanged::dispatch($customer->business_id, $customer->id);
        };

        static::created($dispatch);
        static::updated($dispatch);
    }

    protected function casts(): array
    {
        return [
            'loyalty_points' => 'decimal:4',
            'credit_balance' => 'decimal:4',
            'credit_limit' => 'decimal:4',
            'is_tax_exempt' => 'boolean',
        ];
    }
}
