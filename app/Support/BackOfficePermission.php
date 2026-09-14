<?php

namespace App\Support;

/**
 * The customizable-permission catalogue for BackOffice actions. Deliberately
 * separate from the till app's `Permission` Dart enum (see
 * BackOfficeRolePermission / the 2026_09_03_100000 migration for why) — this
 * is its own smaller, BackOffice-specific vocabulary.
 */
final class BackOfficePermission
{
    // EXISTING
    public const MANAGE_USERS = 'manage_users';

    public const MANAGE_SUPPLIERS = 'manage_suppliers';

    public const MANAGE_CUSTOMERS = 'manage_customers';

    public const MANAGE_PURCHASE_ORDERS = 'manage_purchase_orders';

    public const MANAGE_STOCKTAKES = 'manage_stocktakes';

    public const MANAGE_STOREMAN = 'manage_storeman';

    public const MANAGE_TILLS = 'manage_tills';

    public const ARCHIVE_ALL_PRODUCTS = 'archive_all_products';

    public const MANAGE_APPROVALS = 'manage_approvals';

    public const VIEW_FINANCIAL_STATEMENTS = 'view_financial_statements';

    public const MANAGE_JOURNAL_ENTRIES = 'manage_journal_entries';

    public const MANAGE_REQUISITIONS = 'manage_requisitions';

    public const MANAGE_ASSETS = 'manage_assets';

    // SALES
    public const SALES_VIEW = 'sales.view';

    public const SALES_CREATE = 'sales.create';

    public const SALES_VOID = 'sales.void';

    public const SALES_REFUND = 'sales.refund';

    public const SALES_DISCOUNT_LINE = 'sales.discount.line';

    public const SALES_DISCOUNT_CART = 'sales.discount.cart';

    public const SALES_DISCOUNT_OVERRIDE = 'sales.discount.override';

    public const SALES_PRICE_OVERRIDE = 'sales.price.override';

    public const SALES_CREDIT = 'sales.credit';

    public const SALES_REPRINT = 'sales.reprint';

    public const SALES_EXPORT = 'sales.export';

    // STOCK
    public const STOCK_VIEW = 'stock.view';

    public const STOCK_ADJUST = 'stock.adjust';

    public const STOCK_TRANSFER = 'stock.transfer';

    public const STOCK_TRANSFER_APPROVE = 'stock.transfer.approve';

    public const STOCK_RECEIVE = 'stock.receive';

    public const STOCK_RELEASE = 'stock.release';

    public const STOCK_REQUISITION_CREATE = 'stock.requisition.create';

    public const STOCK_REQUISITION_APPROVE = 'stock.requisition.approve';

    public const STOCK_COUNT = 'stock.count';

    public const STOCK_COUNT_APPROVE = 'stock.count.approve';

    public const STOCK_WRITE_OFF = 'stock.write_off';

    public const STOCK_WRITE_OFF_APPROVE = 'stock.write_off.approve';

    public const STOCK_DISPOSE = 'stock.dispose';

    // PROCUREMENT
    public const PROCUREMENT_PO_VIEW = 'procurement.po.view';

    public const PROCUREMENT_PO_CREATE = 'procurement.po.create';

    public const PROCUREMENT_PO_EDIT = 'procurement.po.edit';

    public const PROCUREMENT_PO_SUBMIT = 'procurement.po.submit';

    public const PROCUREMENT_PO_APPROVE = 'procurement.po.approve';

    public const PROCUREMENT_PO_REJECT = 'procurement.po.reject';

    public const PROCUREMENT_PO_CANCEL = 'procurement.po.cancel';

    public const PROCUREMENT_GRV_CREATE = 'procurement.grv.create';

    public const PROCUREMENT_GRV_APPROVE = 'procurement.grv.approve';

    public const PROCUREMENT_SUPPLIER_VIEW = 'procurement.supplier.view';

    public const PROCUREMENT_SUPPLIER_MANAGE = 'procurement.supplier.manage';

    public const PROCUREMENT_BUDGET_VIEW = 'procurement.budget.view';

    public const PROCUREMENT_BUDGET_MANAGE = 'procurement.budget.manage';

    // FINANCE
    public const FINANCE_GL_VIEW = 'finance.gl.view';

    public const FINANCE_GL_POST = 'finance.gl.post';

    public const FINANCE_GL_REVERSE = 'finance.gl.reverse';

    public const FINANCE_GL_APPROVE = 'finance.gl.approve';

    public const FINANCE_PAYMENT_VIEW = 'finance.payment.view';

    public const FINANCE_PAYMENT_CREATE = 'finance.payment.create';

    public const FINANCE_PAYMENT_APPROVE = 'finance.payment.approve';

    public const FINANCE_RECEIPT_CREATE = 'finance.receipt.create';

    public const FINANCE_BANK_VIEW = 'finance.bank.view';

    public const FINANCE_BANK_RECONCILE = 'finance.bank.reconcile';

    public const FINANCE_PERIOD_CLOSE = 'finance.period.close';

    public const FINANCE_PERIOD_REOPEN = 'finance.period.reopen';

    public const FINANCE_STATEMENTS_VIEW = 'finance.statements.view';

    public const FINANCE_AR_VIEW = 'finance.ar.view';

    public const FINANCE_AR_ADJUST = 'finance.ar.adjust';

    public const FINANCE_AP_VIEW = 'finance.ap.view';

    public const FINANCE_AP_ADJUST = 'finance.ap.adjust';

    public const FINANCE_CREDIT_NOTE_CREATE = 'finance.credit_note.create';

    public const FINANCE_EXCHANGE_RATE_VIEW = 'finance.exchange_rate.view';

    public const FINANCE_EXCHANGE_RATE_EDIT = 'finance.exchange_rate.edit';

    public const FINANCE_WRITE_OFF_CREATE = 'finance.write_off.create';

    public const FINANCE_WRITE_OFF_APPROVE = 'finance.write_off.approve';

    // CUSTOMERS
    public const CUSTOMERS_VIEW = 'customers.view';

    public const CUSTOMERS_CREATE = 'customers.create';

    public const CUSTOMERS_EDIT = 'customers.edit';

    public const CUSTOMERS_DELETE = 'customers.delete';

    public const CUSTOMERS_CREDIT_GRANT = 'customers.credit.grant';

    public const CUSTOMERS_CREDIT_ADJUST = 'customers.credit.adjust';

    public const CUSTOMERS_STATEMENT = 'customers.statement';

    public const CUSTOMERS_PAYMENT_RECEIVE = 'customers.payment.receive';

    // EMPLOYEES
    public const EMPLOYEES_VIEW = 'employees.view';

    public const EMPLOYEES_MANAGE = 'employees.manage';

    public const EMPLOYEES_PAYROLL_VIEW = 'employees.payroll.view';

    public const EMPLOYEES_PAYROLL_PROCESS = 'employees.payroll.process';

    // REPORTS
    public const REPORTS_DAILY = 'reports.daily';

    public const REPORTS_SHIFT = 'reports.shift';

    public const REPORTS_SALES = 'reports.sales';

    public const REPORTS_STOCK = 'reports.stock';

    public const REPORTS_FINANCE = 'reports.finance';

    public const REPORTS_EXPORT = 'reports.export';

    public const REPORTS_AUDIT = 'reports.audit';

    // SETTINGS
    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_BUSINESS = 'settings.business';

    public const SETTINGS_TAX = 'settings.tax';

    public const SETTINGS_PAYMENT_METHODS = 'settings.payment_methods';

    public const SETTINGS_RECEIPT = 'settings.receipt';

    public const SETTINGS_CURRENCY = 'settings.currency';

    public const SETTINGS_USERS = 'settings.users';

    public const SETTINGS_ROLES = 'settings.roles';

    public const SETTINGS_LOCATIONS = 'settings.locations';

    public const SETTINGS_TILLS = 'settings.tills';

    public const SETTINGS_APPROVAL_RULES = 'settings.approval_rules';

    public const SETTINGS_BACKUP = 'settings.backup';

    // APPROVALS
    public const APPROVALS_VIEW = 'approvals.view';

    public const APPROVALS_DECIDE = 'approvals.decide';

    public const APPROVALS_DELEGATE = 'approvals.delegate';

    public const APPROVALS_CONFIGURE = 'approvals.configure';

    // ASSETS
    public const ASSETS_VIEW = 'assets.view';

    public const ASSETS_MANAGE = 'assets.manage';

    public const ASSETS_DEPRECIATE = 'assets.depreciate';

    // PROJECTS
    public const PROJECTS_VIEW = 'projects.view';

    public const PROJECTS_MANAGE = 'projects.manage';

    public const PROJECTS_APPROVE = 'projects.approve';

    /**
     * Single source of truth for the catalogue — all() and label() both
     * derive from this instead of separately re-listing every permission,
     * which used to mean adding one meant editing three parallel lists.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        self::MANAGE_USERS => 'Manage users',
        self::MANAGE_SUPPLIERS => 'Manage suppliers',
        self::MANAGE_CUSTOMERS => 'Manage customers',
        self::MANAGE_PURCHASE_ORDERS => 'Manage purchase orders',
        self::MANAGE_STOCKTAKES => 'Approve/reject stocktakes',
        self::MANAGE_STOREMAN => 'Create suggested transfers',
        self::MANAGE_TILLS => 'Move tills between locations',
        self::ARCHIVE_ALL_PRODUCTS => 'Archive all products',
        self::MANAGE_APPROVALS => 'Approve/reject pending requests',
        self::VIEW_FINANCIAL_STATEMENTS => 'View financial statements',
        self::MANAGE_JOURNAL_ENTRIES => 'Post and reverse manual journal entries',
        self::MANAGE_REQUISITIONS => 'Request and approve stock requisitions',
        self::MANAGE_ASSETS => 'Manage the asset register',

        self::SALES_VIEW => 'View sales',
        self::SALES_CREATE => 'Create sales',
        self::SALES_VOID => 'Void sales',
        self::SALES_REFUND => 'Refund sales',
        self::SALES_DISCOUNT_LINE => 'Apply line discounts',
        self::SALES_DISCOUNT_CART => 'Apply cart discounts',
        self::SALES_DISCOUNT_OVERRIDE => 'Override discount limits',
        self::SALES_PRICE_OVERRIDE => 'Override prices',
        self::SALES_CREDIT => 'Process credit sales',
        self::SALES_REPRINT => 'Reprint receipts',
        self::SALES_EXPORT => 'Export sales',

        self::STOCK_VIEW => 'View stock',
        self::STOCK_ADJUST => 'Adjust stock',
        self::STOCK_TRANSFER => 'Transfer stock',
        self::STOCK_TRANSFER_APPROVE => 'Approve stock transfers',
        self::STOCK_RECEIVE => 'Receive stock',
        self::STOCK_RELEASE => 'Release stock',
        self::STOCK_REQUISITION_CREATE => 'Create stock requisitions',
        self::STOCK_REQUISITION_APPROVE => 'Approve stock requisitions',
        self::STOCK_COUNT => 'Perform stock counts',
        self::STOCK_COUNT_APPROVE => 'Approve stock counts',
        self::STOCK_WRITE_OFF => 'Write off stock',
        self::STOCK_WRITE_OFF_APPROVE => 'Approve stock write-offs',
        self::STOCK_DISPOSE => 'Dispose stock',

        self::PROCUREMENT_PO_VIEW => 'View purchase orders',
        self::PROCUREMENT_PO_CREATE => 'Create purchase orders',
        self::PROCUREMENT_PO_EDIT => 'Edit purchase orders',
        self::PROCUREMENT_PO_SUBMIT => 'Submit purchase orders',
        self::PROCUREMENT_PO_APPROVE => 'Approve purchase orders',
        self::PROCUREMENT_PO_REJECT => 'Reject purchase orders',
        self::PROCUREMENT_PO_CANCEL => 'Cancel purchase orders',
        self::PROCUREMENT_GRV_CREATE => 'Create goods receipt vouchers',
        self::PROCUREMENT_GRV_APPROVE => 'Approve goods receipt vouchers',
        self::PROCUREMENT_SUPPLIER_VIEW => 'View suppliers',
        self::PROCUREMENT_SUPPLIER_MANAGE => 'Manage suppliers',
        self::PROCUREMENT_BUDGET_VIEW => 'View procurement budgets',
        self::PROCUREMENT_BUDGET_MANAGE => 'Manage procurement budgets',

        self::FINANCE_GL_VIEW => 'View general ledger',
        self::FINANCE_GL_POST => 'Post general ledger entries',
        self::FINANCE_GL_REVERSE => 'Reverse general ledger entries',
        self::FINANCE_GL_APPROVE => 'Approve general ledger entries',
        self::FINANCE_PAYMENT_VIEW => 'View payments',
        self::FINANCE_PAYMENT_CREATE => 'Create payments',
        self::FINANCE_PAYMENT_APPROVE => 'Approve payments',
        self::FINANCE_RECEIPT_CREATE => 'Create receipts',
        self::FINANCE_BANK_VIEW => 'View bank accounts',
        self::FINANCE_BANK_RECONCILE => 'Reconcile bank accounts',
        self::FINANCE_PERIOD_CLOSE => 'Close financial periods',
        self::FINANCE_PERIOD_REOPEN => 'Reopen financial periods',
        self::FINANCE_STATEMENTS_VIEW => 'View financial statements',
        self::FINANCE_AR_VIEW => 'View accounts receivable',
        self::FINANCE_AR_ADJUST => 'Adjust accounts receivable',
        self::FINANCE_AP_VIEW => 'View accounts payable',
        self::FINANCE_AP_ADJUST => 'Adjust accounts payable',
        self::FINANCE_CREDIT_NOTE_CREATE => 'Create credit notes',
        self::FINANCE_EXCHANGE_RATE_VIEW => 'View exchange rates',
        self::FINANCE_EXCHANGE_RATE_EDIT => 'Edit exchange rates',
        self::FINANCE_WRITE_OFF_CREATE => 'Create financial write-offs',
        self::FINANCE_WRITE_OFF_APPROVE => 'Approve financial write-offs',

        self::CUSTOMERS_VIEW => 'View customers',
        self::CUSTOMERS_CREATE => 'Create customers',
        self::CUSTOMERS_EDIT => 'Edit customers',
        self::CUSTOMERS_DELETE => 'Delete customers',
        self::CUSTOMERS_CREDIT_GRANT => 'Grant customer credit',
        self::CUSTOMERS_CREDIT_ADJUST => 'Adjust customer credit',
        self::CUSTOMERS_STATEMENT => 'View customer statements',
        self::CUSTOMERS_PAYMENT_RECEIVE => 'Receive customer payments',

        self::EMPLOYEES_VIEW => 'View employees',
        self::EMPLOYEES_MANAGE => 'Manage employees',
        self::EMPLOYEES_PAYROLL_VIEW => 'View payroll',
        self::EMPLOYEES_PAYROLL_PROCESS => 'Process payroll',

        self::REPORTS_DAILY => 'View daily reports',
        self::REPORTS_SHIFT => 'View shift reports',
        self::REPORTS_SALES => 'View sales reports',
        self::REPORTS_STOCK => 'View stock reports',
        self::REPORTS_FINANCE => 'View finance reports',
        self::REPORTS_EXPORT => 'Export reports',
        self::REPORTS_AUDIT => 'View audit reports',

        self::SETTINGS_VIEW => 'View settings',
        self::SETTINGS_BUSINESS => 'Manage business settings',
        self::SETTINGS_TAX => 'Manage tax settings',
        self::SETTINGS_PAYMENT_METHODS => 'Manage payment methods',
        self::SETTINGS_RECEIPT => 'Manage receipt settings',
        self::SETTINGS_CURRENCY => 'Manage currency settings',
        self::SETTINGS_USERS => 'Manage users',
        self::SETTINGS_ROLES => 'Manage roles',
        self::SETTINGS_LOCATIONS => 'Manage locations',
        self::SETTINGS_TILLS => 'Manage tills',
        self::SETTINGS_APPROVAL_RULES => 'Manage approval rules',
        self::SETTINGS_BACKUP => 'Manage backups',

        self::APPROVALS_VIEW => 'View approvals',
        self::APPROVALS_DECIDE => 'Decide approvals',
        self::APPROVALS_DELEGATE => 'Delegate approvals',
        self::APPROVALS_CONFIGURE => 'Configure approvals',

        self::ASSETS_VIEW => 'View assets',
        self::ASSETS_MANAGE => 'Manage assets',
        self::ASSETS_DEPRECIATE => 'Depreciate assets',

        self::PROJECTS_VIEW => 'View projects',
        self::PROJECTS_MANAGE => 'Manage projects',
        self::PROJECTS_APPROVE => 'Approve projects',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * Human label for the roles/permissions management screen.
     */
    public static function label(string $permission): string
    {
        return self::LABELS[$permission] ?? $permission;
    }

    /**
     * Permission set for a role that has never been customized — exactly
     * today's hardcoded behavior (business_owner/manager get everything
     * except owner-only actions; anything else, including a brand new
     * custom role, gets nothing until explicitly granted).
     *
     * @return list<string>
     */
    public static function defaultsFor(string $role): array
    {
        $cashier = [
            self::SALES_VIEW, self::SALES_CREATE, self::SALES_DISCOUNT_LINE, self::SALES_REPRINT,
            self::CUSTOMERS_VIEW, self::REPORTS_DAILY, self::REPORTS_SHIFT, self::STOCK_VIEW, self::STOCK_REQUISITION_CREATE,
        ];
        $senior_cashier = array_merge($cashier, [
            self::SALES_VOID, self::SALES_REFUND, self::SALES_DISCOUNT_CART,
        ]);
        $sales_supervisor = array_merge($senior_cashier, [
            self::SALES_DISCOUNT_OVERRIDE, self::SALES_PRICE_OVERRIDE, self::SALES_CREDIT, self::SALES_EXPORT, self::APPROVALS_VIEW, self::APPROVALS_DECIDE,
        ]);
        $procurement_officer = [
            self::PROCUREMENT_PO_VIEW, self::PROCUREMENT_PO_CREATE, self::PROCUREMENT_PO_EDIT, self::PROCUREMENT_PO_SUBMIT, self::PROCUREMENT_SUPPLIER_VIEW, self::STOCK_VIEW, self::STOCK_RECEIVE,
        ];
        $procurement_manager = array_merge($procurement_officer, [
            self::PROCUREMENT_PO_APPROVE, self::PROCUREMENT_PO_REJECT, self::PROCUREMENT_PO_CANCEL, self::PROCUREMENT_GRV_CREATE, self::PROCUREMENT_GRV_APPROVE, self::PROCUREMENT_SUPPLIER_MANAGE, self::PROCUREMENT_BUDGET_VIEW, self::PROCUREMENT_BUDGET_MANAGE, self::APPROVALS_VIEW, self::APPROVALS_DECIDE,
        ]);
        $warehouse_clerk = [
            self::STOCK_VIEW, self::STOCK_RECEIVE, self::STOCK_RELEASE, self::STOCK_REQUISITION_CREATE,
        ];
        $warehouse_supervisor = array_merge($warehouse_clerk, [
            self::STOCK_ADJUST, self::STOCK_TRANSFER_APPROVE, self::STOCK_COUNT, self::STOCK_COUNT_APPROVE, self::STOCK_WRITE_OFF, self::APPROVALS_VIEW, self::APPROVALS_DECIDE,
        ]);
        $stock_controller = array_merge($warehouse_supervisor, [
            self::STOCK_DISPOSE, self::STOCK_WRITE_OFF_APPROVE,
        ]);
        $finance_officer = [
            self::FINANCE_PAYMENT_VIEW, self::FINANCE_PAYMENT_CREATE, self::FINANCE_RECEIPT_CREATE, self::FINANCE_BANK_VIEW, self::FINANCE_AR_VIEW, self::FINANCE_AP_VIEW, self::FINANCE_STATEMENTS_VIEW, self::FINANCE_EXCHANGE_RATE_VIEW, self::APPROVALS_VIEW,
        ];
        $accountant = array_merge($finance_officer, [
            self::FINANCE_GL_VIEW, self::FINANCE_GL_POST, self::FINANCE_CREDIT_NOTE_CREATE, self::FINANCE_WRITE_OFF_CREATE, self::FINANCE_BANK_RECONCILE, self::APPROVALS_VIEW, self::APPROVALS_DECIDE,
        ]);
        $finance_manager = array_merge($accountant, [
            self::FINANCE_GL_REVERSE, self::FINANCE_GL_APPROVE, self::FINANCE_PAYMENT_APPROVE, self::FINANCE_PERIOD_CLOSE, self::FINANCE_PERIOD_REOPEN, self::FINANCE_AR_ADJUST, self::FINANCE_AP_ADJUST, self::FINANCE_EXCHANGE_RATE_EDIT, self::FINANCE_WRITE_OFF_APPROVE, self::REPORTS_FINANCE,
        ]);
        $hr_officer = [
            self::EMPLOYEES_VIEW, self::EMPLOYEES_MANAGE, self::EMPLOYEES_PAYROLL_VIEW, self::EMPLOYEES_PAYROLL_PROCESS,
        ];

        $views_only = array_filter(self::all(), fn ($p) => str_ends_with($p, '.view'));
        $auditor = array_merge($views_only, [self::REPORTS_AUDIT]);

        $system_admin = array_merge(
            array_filter(self::all(), fn ($p) => str_starts_with($p, 'settings.')),
            [self::APPROVALS_CONFIGURE]
        );

        return match ($role) {
            'business_owner' => self::all(),
            'manager', 'branch_manager' => array_values(array_diff(self::all(), [self::ARCHIVE_ALL_PRODUCTS, self::MANAGE_JOURNAL_ENTRIES, self::MANAGE_ASSETS, self::SETTINGS_USERS, self::SETTINGS_ROLES, self::APPROVALS_CONFIGURE])),
            'cashier' => $cashier,
            'senior_cashier' => $senior_cashier,
            'sales_supervisor' => $sales_supervisor,
            'procurement_officer' => $procurement_officer,
            'procurement_manager' => $procurement_manager,
            'warehouse_clerk' => $warehouse_clerk,
            'warehouse_supervisor' => $warehouse_supervisor,
            'stock_controller' => $stock_controller,
            'finance_officer' => $finance_officer,
            'accountant' => $accountant,
            'finance_manager' => $finance_manager,
            'hr_officer' => $hr_officer,
            'auditor' => $auditor,
            'system_admin' => $system_admin,
            default => [],
        };
    }
}
