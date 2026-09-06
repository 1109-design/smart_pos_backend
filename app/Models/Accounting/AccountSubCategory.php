<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountSubCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'account_category_id', 'name', 'reporting_order',
    ];

    protected function casts(): array
    {
        return [
            'reporting_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AccountCategory::class, 'account_category_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(GlAccount::class);
    }
}
