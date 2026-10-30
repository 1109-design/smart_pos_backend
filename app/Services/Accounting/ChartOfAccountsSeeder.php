<?php

namespace App\Services\Accounting;

use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\AccountSubCategory;
use App\Models\Accounting\GlAccount;
use App\Models\SyncRecord;
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
                    $subCategory = $this->ensureSubCategory($businessId, $category->id, $subDef['name'], $subOrder);

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

            $subCategory = $this->ensureSubCategory($businessId, $category->id, $subCategoryName, 99);

            return $this->createAccount($businessId, $category->id, $subCategory->id, $accountDef);
        });
    }

    private function ensureCategory(string $businessId, array $categoryDef): AccountCategory
    {
        $category = AccountCategory::firstOrCreate(
            ['business_id' => $businessId, 'name' => $categoryDef['name']],
            [
                'code' => $categoryDef['code'],
                'is_debit_normal' => $categoryDef['is_debit_normal'],
                'statement_type' => $categoryDef['statement_type'],
                'reporting_order' => $categoryDef['code'],
                'is_system' => true,
            ]
        );

        if ($category->wasRecentlyCreated) {
            $this->publish('account_categories', $businessId, $category->id, [
                'id' => $category->id,
                'business_id' => $businessId,
                'name' => $category->name,
                'code' => $category->code,
                'is_debit_normal' => $category->is_debit_normal,
                'statement_type' => $category->statement_type,
                'reporting_order' => $category->reporting_order,
                'is_system' => $category->is_system,
            ]);
        }

        return $category;
    }

    private function ensureSubCategory(string $businessId, string $categoryId, string $name, int $reportingOrder): AccountSubCategory
    {
        $subCategory = AccountSubCategory::firstOrCreate(
            ['business_id' => $businessId, 'account_category_id' => $categoryId, 'name' => $name],
            ['reporting_order' => $reportingOrder]
        );

        if ($subCategory->wasRecentlyCreated) {
            $this->publish('account_sub_categories', $businessId, $subCategory->id, [
                'id' => $subCategory->id,
                'business_id' => $businessId,
                'account_category_id' => $subCategory->account_category_id,
                'name' => $subCategory->name,
                'reporting_order' => $subCategory->reporting_order,
            ]);
        }

        return $subCategory;
    }

    private function createAccount(string $businessId, string $categoryId, string $subCategoryId, array $accountDef): GlAccount
    {
        $account = GlAccount::create([
            'business_id' => $businessId,
            'code' => $accountDef['code'],
            'name' => $accountDef['name'],
            'account_category_id' => $categoryId,
            'account_sub_category_id' => $subCategoryId,
            'control_type' => $accountDef['control_type'] ?? null,
            'must_be_positive' => $accountDef['must_be_positive'] ?? false,
        ]);

        $this->publish('gl_accounts', $businessId, $account->id, [
            'id' => $account->id,
            'business_id' => $businessId,
            'code' => $account->code,
            'name' => $account->name,
            'account_category_id' => $account->account_category_id,
            'account_sub_category_id' => $account->account_sub_category_id,
            'allow_direct_posting' => $account->allow_direct_posting,
            'control_type' => $account->control_type,
            'must_be_positive' => $account->must_be_positive,
            'status' => $account->status,
        ]);

        return $account;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publish(string $table, string $businessId, string $uuid, array $payload): void
    {
        SyncRecord::create([
            'business_id' => $businessId,
            'table_name' => $table,
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }

    /**
     * Holds the value of goods a customer hands back in an exchange for the
     * instant between the return and the replacement sale — Cr on the
     * return, Dr on the new sale, always netting to zero. See
     * SalePostingService's 'exchange_credit' tender.
     */
    public const EXCHANGE_CLEARING = ['code' => '2045', 'name' => 'Exchange Clearing'];

    /**
     * Deposits customers paid in the business's previous POS system on goods
     * they hadn't collected at cut-over — loaded as an opening balance, then
     * used up (Dr) as each collection sale applies one through the till's
     * 'legacy_deposit' tender. Its balance is what's still unclaimed.
     */
    public const OLD_SYSTEM_DEPOSITS = ['code' => '2046', 'name' => 'Old-System Customer Deposits'];

    /**
     * Payroll accounts — see PayRunPostingService. A pay run accrues on
     * approval into these payables, which clear when net pay and statutory
     * remittances go out. [category, sub-category, account].
     */
    public const PAYROLL_ACCOUNTS = [
        ['Assets', 'Current Assets', ['code' => '1160', 'name' => 'Staff Loans & Advances']],
        ['Liabilities', 'Current Liabilities', ['code' => '2050', 'name' => 'Net Wages Payable']],
        ['Liabilities', 'Current Liabilities', ['code' => '2051', 'name' => 'PAYE Payable (USD)']],
        ['Liabilities', 'Current Liabilities', ['code' => '2052', 'name' => 'PAYE Payable (ZiG)']],
        ['Liabilities', 'Current Liabilities', ['code' => '2053', 'name' => 'NSSA Payable']],
        ['Liabilities', 'Current Liabilities', ['code' => '2054', 'name' => 'ZIMDEF Payable']],
        ['Liabilities', 'Current Liabilities', ['code' => '2055', 'name' => 'Payroll Deductions Payable']],
        ['Liabilities', 'Current Liabilities', ['code' => '2056', 'name' => 'NEC Levy Payable']],
        ['Expenses', 'Operating Expenses', ['code' => '6025', 'name' => 'Employer Payroll Costs']],
    ];

    /**
     * Where a disposed asset's value goes when it isn't cash — see
     * AssetPostingService::recordDisposal(). A return to the supplier waits
     * in Supplier Refunds Due until the supplier's credit note clears it
     * against Accounts Payable; a theft or loss claimed on insurance waits
     * in Insurance Claims Receivable until the insurer pays.
     * [category, sub-category, account].
     */
    public const ASSET_DISPOSAL_ACCOUNTS = [
        ['Assets', 'Current Assets', ['code' => '1165', 'name' => 'Supplier Refunds Due']],
        ['Assets', 'Current Assets', ['code' => '1170', 'name' => 'Insurance Claims Receivable']],
    ];

    /**
     * Donation accounts — see the till's donation_posting_service.dart.
     * Treated like grants (IFRS for SMEs s24 / IAS 20): an unconditional
     * donation is income; one with conditions waits in Deferred Donations
     * until they're met; one for a capital project waits in Deferred
     * Capital Grants and is released over the asset's life. Donations a
     * business gives away are an expense. [category, sub-category, account].
     */
    public const DONATION_ACCOUNTS = [
        ['Revenue', 'Other Income', ['code' => '4030', 'name' => 'Donations Received']],
        ['Liabilities', 'Current Liabilities', ['code' => '2060', 'name' => 'Deferred Donations']],
        ['Liabilities', 'Long-Term Liabilities', ['code' => '2065', 'name' => 'Deferred Capital Grants']],
        ['Expenses', 'Other Expenses', ['code' => '6085', 'name' => 'Donations Given']],
    ];

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
                    // AP module — recoverable input tax on supplier invoices.
                    ['code' => '1150', 'name' => 'Input VAT'],
                    ['code' => '1160', 'name' => 'Staff Loans & Advances'],
                    ['code' => '1165', 'name' => 'Supplier Refunds Due'],
                    ['code' => '1170', 'name' => 'Insurance Claims Receivable'],
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
                    // AP module — tax withheld from a supplier payment,
                    // owed to the tax authority rather than the supplier.
                    ['code' => '2015', 'name' => 'Withholding Tax Payable'],
                    ['code' => '2020', 'name' => 'Deposits Held'],
                    ['code' => '2030', 'name' => 'Tax Payable'],
                    self::EXCHANGE_CLEARING,
                    self::OLD_SYSTEM_DEPOSITS,
                    ['code' => '2050', 'name' => 'Net Wages Payable'],
                    ['code' => '2051', 'name' => 'PAYE Payable (USD)'],
                    ['code' => '2052', 'name' => 'PAYE Payable (ZiG)'],
                    ['code' => '2053', 'name' => 'NSSA Payable'],
                    ['code' => '2054', 'name' => 'ZIMDEF Payable'],
                    ['code' => '2055', 'name' => 'Payroll Deductions Payable'],
                    ['code' => '2056', 'name' => 'NEC Levy Payable'],
                    ['code' => '2060', 'name' => 'Deferred Donations'],
                ]],
                ['name' => 'Long-Term Liabilities', 'accounts' => [
                    ['code' => '2040', 'name' => 'Loans'],
                    ['code' => '2065', 'name' => 'Deferred Capital Grants'],
                ]],
            ],
        ],
        [
            'name' => 'Equity', 'code' => 3000, 'is_debit_normal' => false, 'statement_type' => 'balance_sheet',
            'sub_categories' => [
                ['name' => "Shareholders' Equity", 'accounts' => [
                    ['code' => '3000', 'name' => "Owner's Capital"],
                    ['code' => '3010', 'name' => 'Retained Earnings'],
                    // AP/AR take-on — the offsetting entry for an opening
                    // balance imported from a business's pre-SmartPOS books.
                    ['code' => '3020', 'name' => 'Opening Balance Equity'],
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
                    // AP module — early-settlement/volume discounts a
                    // supplier grants that reduce the invoice's cost.
                    ['code' => '4015', 'name' => 'Discount Received'],
                    ['code' => '4020', 'name' => 'FX Gain'],
                    ['code' => '4030', 'name' => 'Donations Received'],
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
                    // AP module — carriage/freight charged on a supplier
                    // invoice, kept distinct from general 'Transport'.
                    ['code' => '6015', 'name' => 'Freight & Carriage Inwards'],
                    ['code' => '6020', 'name' => 'Wages'],
                    ['code' => '6025', 'name' => 'Employer Payroll Costs'],
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
                    ['code' => '6085', 'name' => 'Donations Given'],
                ]],
            ],
        ],
    ];
}
