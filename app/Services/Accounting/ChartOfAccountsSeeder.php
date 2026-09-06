<?php

namespace App\Services\Accounting;

use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\AccountSubCategory;
use App\Models\Accounting\GlAccount;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a standard SME hardware-retail chart of accounts for a business —
 * run once at provisioning (see BusinessProvisioner) and available as a
 * one-off backfill for businesses created before Phase 11 shipped (see
 * `php artisan accounting:seed-chart {business} --all`). An owner can
 * rename or add accounts afterward; this only establishes the starting set.
 *
 * Deliberately six categories (Assets/Liabilities/Equity/Revenue/Cost of
 * Sales/Expenses) rather than Blackrock's ten — no separate Contra-Revenue/
 * Other-Income/Income-Tax categories, since a single hardware-store
 * business doesn't need that granularity. See the Phase 11 General Ledger
 * Blueprint for the full design.
 */
class ChartOfAccountsSeeder
{
    public function seedForBusiness(string $businessId): void
    {
        if (AccountCategory::where('business_id', $businessId)->exists()) {
            return;
        }

        DB::transaction(function () use ($businessId) {
            foreach (self::CHART as $categoryDef) {
                $category = $this->ensureCategory($businessId, $categoryDef);

                foreach ($categoryDef['sub_categories'] as $subOrder => $subDef) {
                    $subCategory = AccountSubCategory::create([
                        'business_id' => $businessId,
                        'account_category_id' => $category->id,
                        'name' => $subDef['name'],
                        'reporting_order' => $subOrder,
                    ]);

                    foreach ($subDef['accounts'] as $accountDef) {
                        $this->createAccount($businessId, $category->id, $subCategory->id, $accountDef);
                    }
                }
            }
        });
    }

    /**
     * Backfills one account for a business whose chart was already seeded
     * before this account existed in CHART — e.g. Purchase Price Variance,
     * added when Part B of the Purchasing & Cash Vault Blueprint shipped,
     * needs to exist for businesses seeded back in Phase 11a. A posting
     * service calls this defensively before posting rather than assuming
     * every account it needs is already there. No-op if the account (by
     * code) already exists for the business.
     */
    public function ensureAccount(string $businessId, string $categoryName, string $subCategoryName, array $accountDef): GlAccount
    {
        $existing = GlAccount::where('business_id', $businessId)->where('code', $accountDef['code'])->first();
        if ($existing) {
            return $existing;
        }

        $categoryDef = collect(self::CHART)->firstWhere('name', $categoryName);

        return DB::transaction(function () use ($businessId, $categoryName, $subCategoryName, $categoryDef, $accountDef) {
            $category = $this->ensureCategory($businessId, $categoryDef ?? ['name' => $categoryName, 'code' => 9000, 'is_debit_normal' => true, 'statement_type' => 'income_statement']);

            $subCategory = AccountSubCategory::firstOrCreate(
                ['business_id' => $businessId, 'account_category_id' => $category->id, 'name' => $subCategoryName],
                ['reporting_order' => 99]
            );

            return $this->createAccount($businessId, $category->id, $subCategory->id, $accountDef);
        });
    }

    private function ensureCategory(string $businessId, array $categoryDef): AccountCategory
    {
        return AccountCategory::firstOrCreate(
            ['business_id' => $businessId, 'name' => $categoryDef['name']],
            [
                'code' => $categoryDef['code'],
                'is_debit_normal' => $categoryDef['is_debit_normal'],
                'statement_type' => $categoryDef['statement_type'],
                'reporting_order' => $categoryDef['code'],
                'is_system' => true,
            ]
        );
    }

    private function createAccount(string $businessId, string $categoryId, string $subCategoryId, array $accountDef): GlAccount
    {
        return GlAccount::create([
            'business_id' => $businessId,
            'code' => $accountDef['code'],
            'name' => $accountDef['name'],
            'account_category_id' => $categoryId,
            'account_sub_category_id' => $subCategoryId,
            'control_type' => $accountDef['control_type'] ?? null,
            'must_be_positive' => $accountDef['must_be_positive'] ?? false,
        ]);
    }

    private const CHART = [
        [
            'name' => 'Assets', 'code' => 1000, 'is_debit_normal' => true, 'statement_type' => 'balance_sheet',
            'sub_categories' => [
                ['name' => 'Current Assets', 'accounts' => [
                    ['code' => '1000', 'name' => 'Cash', 'must_be_positive' => true],
                    ['code' => '1005', 'name' => 'Cash Vault', 'must_be_positive' => true],
                    ['code' => '1010', 'name' => 'Bank', 'must_be_positive' => true],
                    ['code' => '1020', 'name' => 'Mobile Money Clearing'],
                    ['code' => '1100', 'name' => 'Accounts Receivable', 'control_type' => 'receivable'],
                    ['code' => '1200', 'name' => 'Inventory', 'control_type' => 'inventory'],
                ]],
                ['name' => 'Fixed Assets', 'accounts' => [
                    ['code' => '1500', 'name' => 'Fixed Assets'],
                    ['code' => '1510', 'name' => 'Accumulated Depreciation'],
                ]],
            ],
        ],
        [
            'name' => 'Liabilities', 'code' => 2000, 'is_debit_normal' => false, 'statement_type' => 'balance_sheet',
            'sub_categories' => [
                ['name' => 'Current Liabilities', 'accounts' => [
                    ['code' => '2000', 'name' => 'Accounts Payable', 'control_type' => 'payable'],
                    ['code' => '2010', 'name' => 'GRN Suspense'],
                    ['code' => '2020', 'name' => 'Deposits Held'],
                    ['code' => '2030', 'name' => 'Tax Payable'],
                ]],
                ['name' => 'Long-Term Liabilities', 'accounts' => [
                    ['code' => '2040', 'name' => 'Loans'],
                ]],
            ],
        ],
        [
            'name' => 'Equity', 'code' => 3000, 'is_debit_normal' => false, 'statement_type' => 'balance_sheet',
            'sub_categories' => [
                ['name' => "Shareholders' Equity", 'accounts' => [
                    ['code' => '3000', 'name' => "Owner's Capital"],
                    ['code' => '3010', 'name' => 'Retained Earnings'],
                ]],
            ],
        ],
        [
            'name' => 'Revenue', 'code' => 4000, 'is_debit_normal' => false, 'statement_type' => 'income_statement',
            'sub_categories' => [
                ['name' => 'Sales', 'accounts' => [
                    ['code' => '4000', 'name' => 'Sales Revenue'],
                ]],
                ['name' => 'Other Income', 'accounts' => [
                    ['code' => '4010', 'name' => 'Other Income'],
                    ['code' => '4020', 'name' => 'FX Gain'],
                ]],
            ],
        ],
        [
            'name' => 'Cost of Sales', 'code' => 5000, 'is_debit_normal' => true, 'statement_type' => 'income_statement',
            'sub_categories' => [
                ['name' => 'Cost of Sales', 'accounts' => [
                    ['code' => '5000', 'name' => 'Cost of Goods Sold'],
                    ['code' => '5010', 'name' => 'Purchase Price Variance'],
                ]],
            ],
        ],
        [
            'name' => 'Expenses', 'code' => 6000, 'is_debit_normal' => true, 'statement_type' => 'income_statement',
            'sub_categories' => [
                ['name' => 'Operating Expenses', 'accounts' => [
                    ['code' => '6000', 'name' => 'Rent'],
                    ['code' => '6010', 'name' => 'Transport'],
                    ['code' => '6020', 'name' => 'Wages'],
                    ['code' => '6030', 'name' => 'Utilities'],
                    ['code' => '6040', 'name' => 'Bank Charges'],
                    ['code' => '6090', 'name' => 'General Expenses'],
                ]],
                ['name' => 'Other Expenses', 'accounts' => [
                    ['code' => '6050', 'name' => 'Stock Loss / Write-offs'],
                    ['code' => '6060', 'name' => 'Cash Rounding Variance'],
                    ['code' => '6065', 'name' => 'Cash Vault Variance'],
                    ['code' => '6070', 'name' => 'Depreciation Expense'],
                    ['code' => '6075', 'name' => 'Gain/Loss on Disposal of Assets'],
                    ['code' => '6080', 'name' => 'FX Loss'],
                ]],
            ],
        ],
    ];
}
