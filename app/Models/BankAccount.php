<?php

namespace App\Models;

use App\Models\Accounting\GlAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'name', 'account_number', 'branch',
        'currency_code', 'gl_account_id', 'is_active', 'accepts_card_swipe',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'accepts_card_swipe' => 'boolean',
        ];
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class);
    }
}
