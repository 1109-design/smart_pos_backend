<?php

namespace App\Services\Audit;

use App\Services\SyncProcessor;

/**
 * Phase 12.2 on the server: changes made here (BackOffice, queue jobs,
 * console) go into audit_logs like a device's. A device's own pushes are
 * left out while they're applied — the device already audited them.
 */
final class ServerAudit
{
    /** > 0 while device sync is applying a push. */
    private static int $deviceSync = 0;

    /** The same master data and financial documents a device audits. */
    public const AUDITED_TABLES = [
        'businesses', 'locations', 'users', 'categories', 'tax_rates',
        'exchange_rates', 'products', 'bundles', 'bundle_items',
        'product_variants', 'product_price_tiers', 'product_tax_rates',
        'customers', 'suppliers', 'supplier_banks', 'coupons',
        'role_permissions', 'tills', 'employees', 'bank_accounts',
        'payment_method_configs', 'gl_accounts', 'account_categories',
        'account_sub_categories', 'account_role_mappings', 'cost_centres',
        'accounting_periods', 'stock_posting_rules', 'approval_rule_sets',
        'approval_rules', 'approval_groups', 'ap_tolerance_settings',
        'transactions', 'payments', 'invoices', 'invoice_payments',
        'credit_notes', 'customer_receipts', 'customer_receipt_allocations',
        'customer_debit_notes', 'customer_adjustments', 'customer_write_offs',
        'sales_returns', 'quotations', 'sales_orders', 'delivery_notes',
        'purchase_orders', 'goods_received_vouchers', 'supplier_invoices',
        'supplier_payments', 'supplier_payment_allocations',
        'supplier_credit_notes', 'payment_vouchers', 'expenses',
        'salary_payments', 'assets', 'journal_headers', 'manual_journals',
        'take_on_batches', 'stock_movements', 'stock_takes', 'stock_transfers',
        'till_cash_movements', 'cash_vault_movements', 'shifts', 'z_reports',
        'fiscal_documents', 'bank_reconciliations',
    ];

    /**
     * Runs [$apply] as a device's push.
     *
     * @template T
     *
     * @param  callable(): T  $apply
     * @return T
     */
    public static function asDeviceSync(callable $apply): mixed
    {
        self::$deviceSync++;
        try {
            return $apply();
        } finally {
            self::$deviceSync--;
        }
    }

    public static function applyingDeviceSync(): bool
    {
        return self::$deviceSync > 0;
    }

    /**
     * The model classes behind the audited tables.
     *
     * @return list<class-string>
     */
    public static function auditedModels(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $table) => SyncProcessor::modelFor($table),
            self::AUDITED_TABLES,
        ))));
    }
}
