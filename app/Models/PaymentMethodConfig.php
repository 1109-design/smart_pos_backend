<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethodConfig extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'business_id',
        'method',
        'currency_code',
        'payment_account_id',
        'provider',
        'gl_account_id',
        'is_enabled',
        'require_reference',
        'allows_change',
        'display_label',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'require_reference' => 'boolean',
            'allows_change' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'payment_account_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }
}
