<?php

use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\GlAccount;
use App\Models\AccountRoleMapping;
use App\Services\Accounting\AccountRoleMappingService;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 1 — wrong postings at the source.
 *
 * 1. Every business with a chart gets 2025 Change Owed, 4050 Sales Returns,
 *    4060 Sales Discounts and 6045 Bad Debts (ChartOfAccountsSeeder::
 *    PHASE1_ACCOUNTS). Without 2025, a sale that left change owed couldn't
 *    balance and was silently kept as a draft.
 * 2. Role mappings still pointing at the accounts the old defaults wrongly
 *    used are moved to the right ones:
 *      bad_debts      6040 Bank Charges        -> 6045 Bad Debts
 *      sales_discount 6065 Cash Vault Variance -> 4060 Sales Discounts
 *      sales_returns  4010 Other Income        -> 4050 Sales Returns
 *    A mapping an owner deliberately set to anything else is left alone.
 *    Both are published, so every device picks them up on its next pull.
 *
 * Journals already posted to the wrong accounts are never edited here —
 * the app's damage report lists them and corrects them by reversal.
 */
return new class extends Migration
{
    /** role => [wrong old default, right account] */
    private const REPOINT = [
        'bad_debts' => ['6040', '6045'],
        'sales_discount' => ['6065', '4060'],
        'sales_returns' => ['4010', '4050'],
    ];

    public function up(): void
    {
        $seeder = app(ChartOfAccountsSeeder::class);
        $roles = app(AccountRoleMappingService::class);

        foreach (AccountCategory::query()->distinct()->pluck('business_id') as $businessId) {
            foreach (ChartOfAccountsSeeder::PHASE1_ACCOUNTS as [$category, $subCategory, $account]) {
                $seeder->ensureAccount($businessId, $category, $subCategory, $account);
            }

            foreach (self::REPOINT as $role => [$wrongCode, $rightCode]) {
                $mapping = AccountRoleMapping::where('business_id', $businessId)->where('role', $role)->first();
                $current = $mapping ? GlAccount::find($mapping->gl_account_id) : null;
                if ($current === null || $current->code !== $wrongCode) {
                    continue;
                }
                $right = GlAccount::where('business_id', $businessId)->where('code', $rightCode)->firstOrFail();
                // Same mapping row (devices hold it by id), new account;
                // published to every device.
                $roles->setMapping($businessId, $role, $right->id);
            }
        }
    }

    public function down(): void
    {
        // Left in place — journals may already post against these accounts.
    }
};
