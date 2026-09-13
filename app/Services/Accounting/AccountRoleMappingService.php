<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\AccountRoleMapping;
use App\Models\SyncRecord;
use RuntimeException;

/**
 * Resolves an abstract posting "role" (e.g. 'salary_expense',
 * 'accounts_payable') to a specific GL account for a business, without any
 * posting service hardcoding a GL code. The first call for a role
 * auto-provisions a sensible default (via ChartOfAccountsSeeder::ensureAccount(),
 * idempotent by code — for any business with a standard seeded chart this
 * just finds the existing account, nothing new is created) and remembers the
 * mapping; every later call — and any reassignment an owner makes from
 * Settings — returns that stored choice instead.
 *
 * Deliberately used only by the newer Salary/Supplier-Payment/Asset posting
 * services — the earlier Sale/Invoice/Credit/CashVault services keep their
 * existing hardcoded codes for now (see the Flutter-First Posting Parity
 * plan's scope note).
 */
class AccountRoleMappingService
{
    /**
     * @var array<string, array{category: string, subCategory: string, code: string, name: string}>
     */
    private const ROLE_DEFAULTS = [
        'default_cash' => ['category' => 'Assets', 'subCategory' => 'Current Assets', 'code' => '1000', 'name' => 'Cash'],
        'default_bank' => ['category' => 'Assets', 'subCategory' => 'Current Assets', 'code' => '1010', 'name' => 'Bank'],
        'default_mobile_money' => ['category' => 'Assets', 'subCategory' => 'Current Assets', 'code' => '1020', 'name' => 'Mobile Money Clearing'],
        'accounts_payable' => ['category' => 'Liabilities', 'subCategory' => 'Current Liabilities', 'code' => '2000', 'name' => 'Accounts Payable'],
        'salary_expense' => ['category' => 'Expenses', 'subCategory' => 'Operating Expenses', 'code' => '6020', 'name' => 'Wages'],
        'fixed_assets' => ['category' => 'Assets', 'subCategory' => 'Fixed Assets', 'code' => '1500', 'name' => 'Fixed Assets'],
        'disposal_gain_loss' => ['category' => 'Expenses', 'subCategory' => 'Other Expenses', 'code' => '6075', 'name' => 'Gain/Loss on Disposal of Assets'],
        // Deliberately NOT mapped: 'accumulated depreciation' would need to
        // match whatever GL account the still-hardcoded, still-server-only
        // monthly depreciation sweep (postMonthlyDepreciation()) posts
        // into — making it independently reassignable here would silently
        // desync a disposal's reversal from what depreciation actually
        // accrued into. Asset disposal reads that account the same
        // hardcoded way the sweep does (Asset::accumulatedDepreciation()),
        // not via this mapping.
    ];

    public function __construct(private readonly ChartOfAccountsSeeder $chartSeeder) {}

    public function resolve(string $businessId, string $role): GlAccount
    {
        $default = self::ROLE_DEFAULTS[$role] ?? null;
        throw_unless($default, new RuntimeException("Unknown account role '{$role}'."));

        $mapping = AccountRoleMapping::where('business_id', $businessId)->where('role', $role)->first();
        if ($mapping) {
            $account = GlAccount::find($mapping->gl_account_id);
            if ($account) {
                return $account;
            }
            // The mapped account vanished (shouldn't happen in practice) —
            // fall through and re-provision rather than leave posting stuck.
        }

        $account = $this->chartSeeder->ensureAccount(
            $businessId,
            $default['category'],
            $default['subCategory'],
            ['code' => $default['code'], 'name' => $default['name']],
        );

        $this->setMapping($businessId, $role, $account->id);

        return $account;
    }

    /**
     * Reassigns a role to a different GL account — what the Settings mapping
     * editor calls. Also the path resolve() uses internally to remember an
     * auto-provisioned default.
     */
    public function setMapping(string $businessId, string $role, string $glAccountId): AccountRoleMapping
    {
        $mapping = AccountRoleMapping::updateOrCreate(
            ['business_id' => $businessId, 'role' => $role],
            ['gl_account_id' => $glAccountId],
        );

        $this->publish($mapping);

        return $mapping;
    }

    /**
     * @return array<string> every role this service knows how to resolve —
     *                       what the Settings mapping editor lists.
     */
    public function knownRoles(): array
    {
        return array_keys(self::ROLE_DEFAULTS);
    }

    private function publish(AccountRoleMapping $mapping): void
    {
        SyncRecord::create([
            'business_id' => $mapping->business_id,
            'table_name' => 'account_role_mappings',
            'record_uuid' => $mapping->id,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $mapping->business_id,
                'role' => $mapping->role,
                'gl_account_id' => $mapping->gl_account_id,
            ],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
