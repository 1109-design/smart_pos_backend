<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierBank extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'business_id',
        'supplier_id',
        'bank_name',
        'account_name',
        'account_number',
        'branch',
        'branch_code',
        'swift_code',
        'currency_code',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
