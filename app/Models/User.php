<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'business_id',
        'name',
        'email',
        'password',
        'pin_hash',
        'is_active',
    ];

    /**
     * Tenant isolation for the single-database architecture.
     *
     * All tenants share one database, so `tenancy()->initialize()` no longer
     * switches connections — it only records the current tenant. Users must
     * therefore be scoped by `business_id` explicitly, exactly as the sync
     * layer already scopes its records. This global scope makes every query
     * run inside an initialized tenant context (device auth, user management)
     * see only that business's users, and back-fills `business_id` on create.
     * When no tenant is initialized (central super-admin dashboard, Sanctum
     * token resolution) the scope is inert so those flows are unaffected.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('business', function (Builder $query): void {
            if (tenancy()->initialized) {
                $query->where($query->getModel()->getTable().'.business_id', tenant('id'));
            }
        });

        static::creating(function (User $user): void {
            if (empty($user->business_id) && tenancy()->initialized) {
                $user->business_id = tenant('id');
            }
        });
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
