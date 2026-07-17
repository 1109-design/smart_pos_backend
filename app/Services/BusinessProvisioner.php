<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BusinessProvisioner
{
    /**
     * Provision a new business: the tenant record, its routing domain, and its
     * first owner user (with the business_owner role). Shared by the admin
     * "create business" flow and public self-registration so both stay in
     * lockstep. Subscription-history events and device pairing are the
     * caller's responsibility since they differ per entry point.
     *
     * @param  array{
     *   business_name: string,
     *   owner_email: string,
     *   tier: string,
     *   subscription_valid_until?: \DateTimeInterface|string|null,
     *   country?: string|null,
     *   currency_code?: string|null,
     *   admin_name: string,
     *   admin_pin: string,
     * }  $data
     */
    public function provision(array $data): Tenant
    {
        $tenant = Tenant::create([
            'business_name' => $data['business_name'],
            'owner_email' => $data['owner_email'],
            'tier' => $data['tier'],
            'subscription_valid_until' => $data['subscription_valid_until'] ?? null,
            'country' => $data['country'] ?? null,
            'currency_code' => $data['currency_code'] ?? 'USD',
        ]);

        $tenant->domains()->create(['domain' => $this->uniqueDomain($tenant)]);

        // Bootstrap the owner inside the tenant context. business_id is set
        // explicitly (and would also be back-filled by the User creating hook)
        // so the owner is correctly scoped to this business. The finally block
        // guarantees we always revert to the central context even if role
        // assignment throws.
        tenancy()->initialize($tenant);

        try {
            $owner = User::create([
                'business_id' => $tenant->id,
                'name' => $data['admin_name'],
                'email' => $data['owner_email'],
                'password' => Hash::make(Str::random(24)),
                'pin_hash' => Hash::make($data['admin_pin']),
                'is_active' => true,
            ]);

            $owner->assignRole('business_owner');
        } finally {
            tenancy()->end();
        }

        return $tenant;
    }

    /**
     * Derive a unique routing domain. The domains table enforces uniqueness,
     * and self-registration makes duplicate business names likely, so the
     * tenant's already-unique pairing code is appended as a stable suffix.
     */
    private function uniqueDomain(Tenant $tenant): string
    {
        $slug = Str::slug($tenant->business_name) ?: 'business';

        return $slug.'-'.Str::lower($tenant->pairing_code);
    }
}
