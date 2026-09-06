<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'name', 'code', 'is_debit_normal',
        'statement_type', 'reporting_order', 'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_debit_normal' => 'boolean',
            'is_system' => 'boolean',
            'reporting_order' => 'integer',
        ];
    }

    public function subCategories(): HasMany
    {
        return $this->hasMany(AccountSubCategory::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(GlAccount::class);
    }
}
