<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
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
     *   admin_password?: string|null,
     * }  $data
     */
    public function provision(array $data): Tenant
    {
        // Single-database tenancy (see App\Models\Tenant docblock) — all of this
        // lives on one connection, so a single transaction keeps the tenant,
        // its domain, and its owner user atomic. Without it, a failure in user
        // creation or role assignment would leave an orphaned, ownerless business.
        return DB::transaction(function () use ($data) {
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
            // assignment throws (which also rolls back the transaction above).
            tenancy()->initialize($tenant);

            try {
                $owner = User::create([
                    'business_id' => $tenant->id,
                    'name' => $data['admin_name'],
                    'email' => $data['owner_email'],
                    // The owner's BackOffice password. When not supplied (legacy
                    // callers), an unusable random hash — never a guessable value.
                    'password' => Hash::make($data['admin_password'] ?? Str::random(40)),
                    'pin_hash' => Hash::make($data['admin_pin']),
                    'is_active' => true,
                ]);

                $owner->assignRole('business_owner');

                (new ChartOfAccountsSeeder)->seedForBusiness($tenant->id);

                // Central Approval Stage Engine: intentionally NOT calling
                // DefaultApprovalRulesSeeder::seedForBusiness() here anymore.
                // A process with zero configured ApprovalRule rows is meant
                // to behave as if approval doesn't exist for it — no PIN
                // prompt, no gate — until the owner opts in via the till's
                // approval-config screen and assigns named approver groups
                // to stages. Auto-seeding role-based rules here would leave
                // every new business silently gated on processes nobody
                // configured. DefaultApprovalRulesSeeder itself is left in
                // place for manual/test use, just no longer auto-invoked.
            } finally {
                tenancy()->end();
            }

            return $tenant;
        });
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
