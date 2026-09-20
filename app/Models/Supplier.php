<?php

namespace App\Models;

use App\Events\SupplierChanged;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'name', 'contact_name', 'phone', 'email',
        'address', 'website', 'notes', 'tax_number', 'is_active',
        // AP module.
        'supplier_code', 'trading_name', 'currency_code', 'payment_terms_days',
        'credit_limit', 'category', 'tax_status', 'bank_name',
        'bank_account_number', 'bank_branch', 'control_account_id',
    ];

    protected static function booted(): void
    {
        $dispatch = function (Supplier $supplier): void {
            if (! $supplier->business_id) {
                return;
            }

            SupplierChanged::dispatch($supplier->business_id, $supplier->id);
        };

        static::created($dispatch);
        static::updated($dispatch);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'payment_terms_days' => 'integer',
            'credit_limit' => 'decimal:4',
        ];
    }

    public function banks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupplierBank::class);
    }
}
