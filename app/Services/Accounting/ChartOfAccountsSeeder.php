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
     * Accounts the posting roles need that older charts lacked (Phase 1):
     * change still owed to a customer (a liability until claimed), the
     * contra-revenue accounts sales returns and sales discounts belong in
     * (they used to fall into Other Income / Cash Vault Variance), and Bad
     * Debts (which used to fall into Bank Charges). [category,
     * sub-category, account].
     */
    public const PHASE1_ACCOUNTS = [
        ['Liabilities', 'Current Liabilities', ['code' => '2025', 'name' => 'Change Owed']],
        ['Revenue', 'Sales', ['code' => '4050', 'name' => 'Sales Returns']],
        ['Revenue', 'Sales', ['code' => '4060', 'name' => 'Sales Discounts']],
        ['Expenses', 'Other Expenses', ['code' => '6045', 'name' => 'Bad Debts']],
    ];

    /** Month-end revaluation (Phase 9), apart from realised gains / losses. */
    public const PHASE9_ACCOUNTS = [
        ['Revenue', 'Other Income', ['code' => '4025', 'name' => 'Unrealised FX Gain']],
        ['Expenses', 'Other Expenses', ['code' => '6085', 'name' => 'Unrealised FX Loss']],
    ];

    /** Payment vouchers (Phase 8): approved expense vouchers not yet paid. */
    public const PHASE8_ACCOUNTS = [
        ['Liabilities', 'Current Liabilities', ['code' => '2070', 'name' => 'Payment Vouchers Payable']],
    ];

    /** Till (Phase 7): cash shortages / overages, and lay-by money held. */
    public const PHASE7_ACCOUNTS = [
        ['Expenses', 'Other Expenses', ['code' => '6067', 'name' => 'Cash Over/Short']],
        ['Liabilities', 'Current Liabilities', ['code' => '2060', 'name' => 'Layby Deposits']],
    ];

    /** Stock between locations (Phase 6): a transfer's dispatched value. */
    public const PHASE6_ACCOUNTS = [
        ['Assets', 'Current Assets', ['code' => '1210', 'name' => 'Stock in Transit']],
    ];

    /**
     * Take-on (Phase 3): every opening balance posts against Take-on
     * Clearing, which must be exactly zero before take-on is finalised;
     * supplier prepayments brought forward need an asset of their own.
     */
    public const PHASE3_ACCOUNTS = [
        ['Assets', 'Current Assets', ['code' => '1170', 'name' => 'Supplier Prepayments']],
        ['Equity', "Shareholders' Equity", ['code' => '3025', 'name' => 'Take-on Clearing']],
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
                    ['code' => '1170', 'name' => 'Supplier Prepayments'],
                    ['code' => '1200', 'name' => 'Inventory', 'control_type' => 'inventory'],
                    ['code' => '1210', 'name' => 'Stock in Transit'],
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
                    ['code' => '2025', 'name' => 'Change Owed'],
                    ['code' => '2060', 'name' => 'Layby Deposits'],
                    ['code' => '2070', 'name' => 'Payment Vouchers Payable'],
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
                    // AP/AR take-on — the offsetting entry for an opening
                    // balance imported from a business's pre-SmartPOS books.
                    ['code' => '3020', 'name' => 'Opening Balance Equity'],
                    // Take-on (Phase 3) — the other side of every opening
                    // balance; must net to zero before take-on is finalised.
                    ['code' => '3025', 'name' => 'Take-on Clearing'],
                ]],
            ],
        ],
        [
            'name' => 'Revenue', 'code' => 4000, 'is_debit_normal' => false, 'statement_type' => 'income_statement',
            'sub_categories' => [
                ['name' => 'Sales', 'accounts' => [
                    ['code' => '4000', 'name' => 'Sales Revenue'],
                    // Contra-revenue: carry debit balances, shown as
                    // deductions from sales.
                    ['code' => '4050', 'name' => 'Sales Returns'],
                    ['code' => '4060', 'name' => 'Sales Discounts'],
                ]],
                ['name' => 'Other Income', 'accounts' => [
                    ['code' => '4010', 'name' => 'Other Income'],
                    // AP module — early-settlement/volume discounts a
                    // supplier grants that reduce the invoice's cost.
                    ['code' => '4015', 'name' => 'Discount Received'],
                    ['code' => '4020', 'name' => 'FX Gain'],
                    ['code' => '4025', 'name' => 'Unrealised FX Gain'],
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
                    ['code' => '6045', 'name' => 'Bad Debts'],
                    ['code' => '6050', 'name' => 'Stock Loss / Write-offs'],
                    ['code' => '6060', 'name' => 'Cash Rounding Variance'],
                    ['code' => '6065', 'name' => 'Cash Vault Variance'],
                    ['code' => '6067', 'name' => 'Cash Over/Short'],
                    ['code' => '6070', 'name' => 'Depreciation Expense'],
                    ['code' => '6075', 'name' => 'Gain/Loss on Disposal of Assets'],
                    ['code' => '6080', 'name' => 'FX Loss'],
                    ['code' => '6085', 'name' => 'Unrealised FX Loss'],
                ]],
            ],
        ],
    ];
}
