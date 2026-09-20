<?php

namespace App\Models;

use App\Events\BankAccountChanged;
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
        'show_on_documents',
    ];

    protected static function booted(): void
    {
        $dispatch = function (BankAccount $bankAccount): void {
            if (! $bankAccount->business_id) {
                return;
            }

            BankAccountChanged::dispatch($bankAccount->business_id, $bankAccount->id);
        };

        static::created($dispatch);
        static::updated($dispatch);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'accepts_card_swipe' => 'boolean',
            'show_on_documents' => 'boolean',
        ];
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class);
    }
}
