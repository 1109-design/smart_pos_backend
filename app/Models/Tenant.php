<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'pairing_code',
            'business_name',
            'owner_email',
            'tier',
            'subscription_valid_until',
            'is_active',
            'country',
            'currency_code',
            'logo_path',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tenant) {
            if (empty($tenant->pairing_code)) {
                $tenant->pairing_code = self::generateUniquePairingCode();
            }
        });
    }

    public static function generateUniquePairingCode(): string
    {
        do {
            $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
        } while (self::where('pairing_code', $code)->exists());

        return $code;
    }

    protected $casts = [
        'subscription_valid_until' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'tenant_id');
    }

    public function getDatabaseName(): string
    {
        return config('tenancy.database.prefix') .
            $this->getKey() .
            config('tenancy.database.suffix');
    }

    public function subscriptionHistory(): HasMany
    {
        return $this->hasMany(SubscriptionHistory::class, 'tenant_id')->orderBy('created_at', 'desc');
    }

    public function activationCodes(): HasMany
    {
        return $this->hasMany(ActivationCode::class, 'tenant_id');
    }

    public function isSubscriptionActive(): bool
    {
        if ($this->tier === 'starter') {
            return true;
        }
        return $this->subscription_valid_until?->isFuture() ?? false;
    }
}
