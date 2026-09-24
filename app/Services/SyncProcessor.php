<?php

namespace App\Services;

use App\Exceptions\MissingParentRecordException;
use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\AccountSubCategory;
use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Accounting\JournalLine;
use App\Models\AccountRoleMapping;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalGroup;
use App\Models\ApprovalGroupMember;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestStageDecision;
use App\Models\ApprovalRule;
use App\Models\ApprovalRuleSet;
use App\Models\ArCollectionActivity;
use App\Models\ArDispute;
use App\Models\ArPromiseToPay;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\Bundle;
use App\Models\BundleItem;
use App\Models\Business;
use App\Models\Category;
use App\Models\ChangeOwedLedger;
use App\Models\ContainerDepositLedger;
use App\Models\Coupon;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\CreditTransaction;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerAdjustment;
use App\Models\CustomerDebitNote;
use App\Models\CustomerDebitNoteItem;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\CustomerReconciliation;
use App\Models\CustomerReconciliationItem;
use App\Models\CustomerWriteOff;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\DocumentBrandingSetting;
use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\GoodsReceivedVoucher;
use App\Models\GrvItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Location;
use App\Models\LoyaltyTransaction;
use App\Models\MilestoneTask;
use App\Models\Payment;
use App\Models\PoAuditLog;
use App\Models\ProcurementBudget;
use App\Models\Product;
use App\Models\ProductContainerLink;
use App\Models\ProductPriceTier;
use App\Models\ProductSellableLocation;
use App\Models\ProductStock;
use App\Models\ProductTaxRate;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\ProductVariantStock;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\RecurringInvoiceSchedule;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\RolePermission;
use App\Models\SalaryPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SheetCut;
use App\Models\SheetLossRecord;
use App\Models\SheetLot;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\StockOversell;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\SupplierBank;
use App\Models\SupplierCreditNote;
use App\Models\SupplierCreditNoteLine;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierReconciliation;
use App\Models\SupplierReconciliationItem;
use App\Models\SyncRecord;
use App\Models\TaxRate;
use App\Models\Till;
use App\Models\TillCashMovement;
use App\Models\TillLocationAudit;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionTax;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\WarehouseBin;
use App\Services\Accounting\AssetPostingService;
use App\Services\Accounting\CreditPaymentPostingService;
use App\Services\Accounting\ExpensePostingService;
use App\Services\Accounting\GrvPostingService;
use App\Services\Accounting\InvoicePaymentPostingService;
use App\Services\Accounting\OpeningBalanceService;
use App\Services\Accounting\ProductOpeningStockPostingService;
use App\Services\Accounting\PurchaseOrderApprovalGate;
use App\Services\Accounting\SalaryPostingService;
use App\Services\Accounting\SalePostingService;
use App\Services\Accounting\StockTakePostingService;
use App\Services\Accounting\SupplierPaymentService;
use App\Services\Zimra\ZimraSalesService;
use App\Support\BackOfficePermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncProcessor
{
    // Tables whose records are immutable — delete operations are ignored
    private const IMMUTABLE = [
        'stock_movements', 'loyalty_transactions', 'credit_transactions',
        'transaction_items', 'transaction_taxes', 'payments', 'po_audit_logs',
        'container_deposit_ledger', 'change_owed_ledger', 'till_cash_movements',
        'invoice_payments', 'credit_note_items', 'sheet_cuts',
        // GLS·02 — a loss record is never deleted or mutated; a wrong entry
        // is corrected by a reversing adjustment elsewhere, same convention
        // as general_ledger below.
        'sheet_loss_records',
        // GLS·03 — no delete UI exists for a bin; safer to leave an
        // orphaned one than strand a sheet_lots.warehouse_bin_id reference.
        'warehouse_bins',
        // Client-posted (or server-posted, pre-cutover) journals — see
        // JournalLine/GeneralLedgerEntry's own model-level immutability
        // guards. A correction is a reversal, never a delete.
        'journal_headers', 'journal_lines', 'general_ledger',
        // A reconciliation session is closed by flipping its status to
        // cancelled/completed, never deleted — see BankReconciliationService.
        'bank_reconciliations',
        // Append-only, same reasoning as invoice_payments — a correction is
        // a new offsetting entry, never a delete.
        'supplier_payments',
        // An asset is disposed (status: disposed), never deleted — see
        // AssetPostingService::recordDisposal().
        'assets',
        // Central Approval Stage Engine's append-only audit trail — a
        // decision, once recorded, is never removed.
        'approval_request_stage_decisions',
        // AP module — a receiving document, once posted (Dr Inventory / Cr
        // GRN Suspense), is corrected by a later adjustment, never deleted.
        'goods_received_vouchers', 'grv_items',
        // AP module — a supplier invoice's own LINES are immutable once
        // created, matching 'credit_note_items' above; the invoice header
        // itself stays mutable while status is still 'draft' (see the
        // 'supplier_invoices' upsert case's own status-transition guard).
        'supplier_invoice_lines',
        // AP module — which invoice(s) a payment was applied to is a
        // financial fact, corrected by a new offsetting allocation, never
        // an edit or delete — same reasoning as 'supplier_payments' itself.
        'supplier_payment_allocations',
        'supplier_credit_note_lines',
        // AP module — a reconciliation session's own line items are a
        // frozen snapshot of what was found at that point in time, never
        // edited after the fact (matches 'credit_note_items' above).
        'supplier_reconciliation_items',
    ];

    // Tables with their own business_id column, guarded in assertOwnership().
    // `users` is included here even though the model also carries its own
    // Eloquent global scope — that scope only applies when tenancy() has been
    // initialized, which never happens on the device sync API path, so it is
    // inert for push()/pull() and this explicit check is the real protection
    // there.
    private const TENANT_SCOPED_MODELS = [
        'locations' => Location::class,
        'categories' => Category::class,
        'tax_rates' => TaxRate::class,
        'exchange_rates' => ExchangeRate::class,
        'customers' => Customer::class,
        'transactions' => Transaction::class,
        'stock_movements' => StockMovement::class,
        'suppliers' => Supplier::class,
        'purchase_orders' => PurchaseOrder::class,
        'coupons' => Coupon::class,
        'shifts' => Shift::class,
        'expenses' => Expense::class,
        'stock_takes' => StockTake::class,
        'employees' => Employee::class,
        'salary_payments' => SalaryPayment::class,
        'bundles' => Bundle::class,
        'products' => Product::class,
        'stock_transfers' => StockTransfer::class,
        'approval_requests' => ApprovalRequest::class,
        'requisitions' => Requisition::class,
        'projects' => Project::class,
        'sheet_lots' => SheetLot::class,
        'sheet_loss_records' => SheetLossRecord::class,
        'warehouse_bins' => WarehouseBin::class,
        'users' => User::class,
        'container_deposit_ledger' => ContainerDepositLedger::class,
        'change_owed_ledger' => ChangeOwedLedger::class,
        'tills' => Till::class,
        'till_cash_movements' => TillCashMovement::class,
        'quotations' => Quotation::class,
        'invoices' => Invoice::class,
        'credit_notes' => CreditNote::class,
        'recurring_invoice_schedules' => RecurringInvoiceSchedule::class,
        'procurement_budgets' => ProcurementBudget::class,
        'units_of_measure' => UnitOfMeasure::class,
        'journal_headers' => JournalHeader::class,
        'general_ledger' => GeneralLedgerEntry::class,
        'bank_accounts' => BankAccount::class,
        'bank_reconciliations' => BankReconciliation::class,
        'account_role_mappings' => AccountRoleMapping::class,
        'supplier_payments' => SupplierPayment::class,
        'assets' => Asset::class,
        'approval_rule_sets' => ApprovalRuleSet::class,
        'approval_rules' => ApprovalRule::class,
        'approval_delegations' => ApprovalDelegation::class,
        'approval_groups' => ApprovalGroup::class,
        'approval_group_members' => ApprovalGroupMember::class,
        'approval_request_stage_decisions' => ApprovalRequestStageDecision::class,
        // Chart of accounts is normally seeded and managed server-side only
        // (see sync_service.dart's `_pullOnlyTables` doc comment) — these two
        // become bidirectional for exactly one narrow case: a bank account
        // created offline mints its own GlAccount (under a "Bank Accounts"
        // AccountSubCategory) locally first and pushes both up. See
        // BankAccountService (Flutter) and BankAccountService::create()
        // (here) for the two mirrored provisioning paths.
        'account_sub_categories' => AccountSubCategory::class,
        'gl_accounts' => GlAccount::class,
        // AP module.
        'goods_received_vouchers' => GoodsReceivedVoucher::class,
        'supplier_invoices' => SupplierInvoice::class,
        'supplier_credit_notes' => SupplierCreditNote::class,
        'supplier_payment_allocations' => SupplierPaymentAllocation::class,
        'supplier_reconciliations' => SupplierReconciliation::class,
        'supplier_banks' => SupplierBank::class,
        // AR module.
        'sales_orders' => SalesOrder::class,
        'delivery_notes' => DeliveryNote::class,
        'customer_receipts' => CustomerReceipt::class,
        'customer_receipt_allocations' => CustomerReceiptAllocation::class,
        'customer_debit_notes' => CustomerDebitNote::class,
        'customer_adjustments' => CustomerAdjustment::class,
        'customer_write_offs' => CustomerWriteOff::class,
        'ar_collection_activities' => ArCollectionActivity::class,
        'ar_promises_to_pay' => ArPromiseToPay::class,
        'ar_disputes' => ArDispute::class,
        'customer_reconciliations' => CustomerReconciliation::class,
    ];

    // Child tables scoped only through a parent record: table => [own model,
    // own FK column pointing at the parent]. assertOwnership() resolves each
    // parent id to a business_id via resolveParentOwner() below.
    private const CHILD_SCOPED_MODELS = [
        'product_stock' => [ProductStock::class, 'product_id'],
        'product_variants' => [ProductVariant::class, 'product_id'],
        'product_variant_stock' => [ProductVariantStock::class, 'variant_id'],
        'stock_transfer_items' => [StockTransferItem::class, 'stock_transfer_id'],
        'requisition_items' => [RequisitionItem::class, 'requisition_id'],
        'sheet_cuts' => [SheetCut::class, 'sheet_lot_id'],
        'bundle_items' => [BundleItem::class, 'bundle_id'],
        'product_container_links' => [ProductContainerLink::class, 'beverage_product_id'],
        'transaction_items' => [TransactionItem::class, 'transaction_id'],
        'transaction_taxes' => [TransactionTax::class, 'transaction_id'],
        'payments' => [Payment::class, 'transaction_id'],
        'loyalty_transactions' => [LoyaltyTransaction::class, 'customer_id'],
        'credit_transactions' => [CreditTransaction::class, 'customer_id'],
        'purchase_order_items' => [PurchaseOrderItem::class, 'purchase_order_id'],
        'stock_take_items' => [StockTakeItem::class, 'stock_take_id'],
        'po_audit_logs' => [PoAuditLog::class, 'po_id'],
        'quotation_items' => [QuotationItem::class, 'quotation_id'],
        'invoice_items' => [InvoiceItem::class, 'invoice_id'],
        'invoice_payments' => [InvoicePayment::class, 'invoice_id'],
        'credit_note_items' => [CreditNoteItem::class, 'credit_note_id'],
        'product_units' => [ProductUnit::class, 'product_id'],
        'product_price_tiers' => [ProductPriceTier::class, 'product_id'],
        'project_milestones' => [ProjectMilestone::class, 'project_id'],
        'milestone_tasks' => [MilestoneTask::class, 'milestone_id'],
        'journal_lines' => [JournalLine::class, 'journal_header_id'],
        // AP module.
        'grv_items' => [GrvItem::class, 'grv_id'],
        'supplier_invoice_lines' => [SupplierInvoiceLine::class, 'supplier_invoice_id'],
        'supplier_credit_note_lines' => [SupplierCreditNoteLine::class, 'supplier_credit_note_id'],
        'supplier_reconciliation_items' => [SupplierReconciliationItem::class, 'reconciliation_id'],
        // AR module.
        'sales_order_items' => [SalesOrderItem::class, 'sales_order_id'],
        'delivery_note_items' => [DeliveryNoteItem::class, 'delivery_note_id'],
        'customer_debit_note_items' => [CustomerDebitNoteItem::class, 'customer_debit_note_id'],
        'customer_reconciliation_items' => [CustomerReconciliationItem::class, 'customer_reconciliation_id'],
    ];

    // Deliberately unguarded, and why:
    //  - currencies: shared reference data, keyed by currency code, not owned by any one business.
    //  - role_permissions: updateOrCreate() is keyed on (business_id, role) together, so a mismatched
    //    business_id can only create/update the caller's OWN row — it can never touch another
    //    tenant's row of the same role name. Safe by construction.

    /**
     * @param  bool  $trusted  False only for payloads that originated from a
     *                         device sync push (or a conflict-resolution replay
     *                         of one) — see the 'tills' case, which only lets an
     *                         untrusted payload move an existing till to a
     *                         different location when $actingUser actually holds
     *                         manage_tills; a bare or under-privileged push still
     *                         gets refused. Every other call site here is
     *                         server-authored (BackOffice controllers, artisan
     *                         commands), so the default is true.
     */
    public function process(string $table, string $uuid, string $operation, array $payload, bool $trusted = true, ?User $actingUser = null): void
    {
        $this->assertOwnership($table, $uuid, $payload);

        if ($operation === 'delete') {
            $this->handleDelete($table, $uuid);

            return;
        }

        $this->handleUpsert($table, $uuid, $payload, $trusted, $actingUser);
    }

    /**
     * Reject a write/delete that would touch another business's record.
     * updateOrCreate()/delete() below key purely on `id` (or a parent FK, for
     * child tables), with no tenant check of their own — this is the one
     * place that stands between an authenticated device and another tenant's
     * data.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function assertOwnership(string $table, string $uuid, array $payload): void
    {
        $businessId = $payload['business_id'] ?? null;

        if ($table === 'businesses') {
            if ($businessId === null || $uuid !== $businessId) {
                throw new \RuntimeException('businesses: a device may only sync its own business record.');
            }

            return;
        }

        if ($table === 'product_tax_rates') {
            $this->assertProductTaxRateOwnership($uuid, $payload, $businessId);

            return;
        }

        if ($table === 'product_sellable_locations') {
            $this->assertProductSellableLocationOwnership($uuid, $payload, $businessId);

            return;
        }

        if ($model = self::TENANT_SCOPED_MODELS[$table] ?? null) {
            if (empty($businessId)) {
                throw new \RuntimeException("{$table}: business_id is required to sync this record.");
            }

            $existingBusinessId = $model::where('id', $uuid)->value('business_id');

            if ($existingBusinessId !== null && (string) $existingBusinessId !== (string) $businessId) {
                throw new \RuntimeException("{$table}: record belongs to a different business.");
            }

            return;
        }

        [$childModel, $fkColumn] = self::CHILD_SCOPED_MODELS[$table] ?? [null, null];
        if (! $childModel) {
            return;
        }

        if (empty($businessId)) {
            throw new \RuntimeException("{$table}: business_id is required to sync this record.");
        }

        // 1. If this child record already exists, its CURRENT parent must belong to
        // the caller — otherwise a device could hijack an existing child row by
        // re-parenting it to a parent it owns.
        $currentParentId = $childModel::where('id', $uuid)->value($fkColumn);
        if ($currentParentId) {
            $currentOwner = $this->resolveParentOwner($table, $currentParentId);
            if ($currentOwner !== null && (string) $currentOwner !== (string) $businessId) {
                throw new \RuntimeException("{$table}: record belongs to a different business.");
            }
        }

        // 2. The parent referenced in the INCOMING payload must belong to the caller —
        // otherwise a device could attach a new (or re-point an existing) child at a
        // parent it doesn't own.
        $incomingParentId = $payload[$fkColumn] ?? null;
        if ($incomingParentId) {
            $targetOwner = $this->resolveParentOwner($table, $incomingParentId);
            if ($targetOwner === null) {
                // Distinguished from the ownership-mismatch case below: the
                // parent simply hasn't arrived on the server yet (a real
                // out-of-order push, not a security violation) — the caller
                // (SyncController::push) catches this specific type to defer
                // the record into pending_sync_records instead of rejecting
                // it outright. See resolvePendingRecords().
                throw new MissingParentRecordException($table);
            }
            if ((string) $targetOwner !== (string) $businessId) {
                throw new \RuntimeException("{$table}: referenced parent does not belong to this business.");
            }
        }
    }

    protected function resolveParentOwner(string $table, string $parentId): ?string
    {
        return match ($table) {
            'product_stock', 'product_variants', 'product_units', 'product_price_tiers' => Product::where('id', $parentId)->value('business_id'),
            'product_variant_stock' => Product::query()
                ->join('product_variants', 'product_variants.product_id', '=', 'products.id')
                ->where('product_variants.id', $parentId)
                ->value('products.business_id'),
            'stock_transfer_items' => StockTransfer::where('id', $parentId)->value('business_id'),
            'requisition_items' => Requisition::where('id', $parentId)->value('business_id'),
            'sheet_cuts' => SheetLot::where('id', $parentId)->value('business_id'),
            'bundle_items' => Bundle::where('id', $parentId)->value('business_id'),
            'product_container_links' => Product::where('id', $parentId)->value('business_id'),
            'transaction_items', 'transaction_taxes', 'payments' => Transaction::where('id', $parentId)->value('business_id'),
            'loyalty_transactions', 'credit_transactions' => Customer::where('id', $parentId)->value('business_id'),
            'purchase_order_items', 'po_audit_logs' => PurchaseOrder::where('id', $parentId)->value('business_id'),
            'stock_take_items' => StockTake::where('id', $parentId)->value('business_id'),
            'quotation_items' => Quotation::where('id', $parentId)->value('business_id'),
            'invoice_items', 'invoice_payments' => Invoice::where('id', $parentId)->value('business_id'),
            'credit_note_items' => CreditNote::where('id', $parentId)->value('business_id'),
            'project_milestones' => Project::where('id', $parentId)->value('business_id'),
            'milestone_tasks' => Project::query()
                ->join('project_milestones', 'project_milestones.project_id', '=', 'projects.id')
                ->where('project_milestones.id', $parentId)
                ->value('projects.business_id'),
            'journal_lines' => JournalHeader::where('id', $parentId)->value('business_id'),
            // AP module.
            'grv_items' => GoodsReceivedVoucher::where('id', $parentId)->value('business_id'),
            'supplier_invoice_lines' => SupplierInvoice::where('id', $parentId)->value('business_id'),
            'supplier_credit_note_lines' => SupplierCreditNote::where('id', $parentId)->value('business_id'),
            'supplier_reconciliation_items' => SupplierReconciliation::where('id', $parentId)->value('business_id'),
            // AR module.
            'sales_order_items' => SalesOrder::where('id', $parentId)->value('business_id'),
            'delivery_note_items' => DeliveryNote::where('id', $parentId)->value('business_id'),
            'customer_debit_note_items' => CustomerDebitNote::where('id', $parentId)->value('business_id'),
            'customer_reconciliation_items' => CustomerReconciliation::where('id', $parentId)->value('business_id'),
            default => null,
        };
    }

    /**
     * product_tax_rates uses a composite (product_id, tax_rate_id) key instead
     * of a single uuid, so it can't share the generic child-table path above.
     */
    protected function assertProductTaxRateOwnership(string $uuid, array $payload, ?string $businessId): void
    {
        if (empty($businessId)) {
            throw new \RuntimeException('product_tax_rates: business_id is required to sync this record.');
        }

        $parts = explode('|', $uuid);
        $productId = count($parts) === 2 ? $parts[0] : ($payload['product_id'] ?? '');
        $taxRateId = count($parts) === 2 ? $parts[1] : ($payload['tax_rate_id'] ?? '');

        $productOwner = $productId ? Product::where('id', $productId)->value('business_id') : null;
        if ($productOwner === null || (string) $productOwner !== (string) $businessId) {
            throw new \RuntimeException('product_tax_rates: referenced product does not belong to this business.');
        }

        $taxRateOwner = $taxRateId ? TaxRate::where('id', $taxRateId)->value('business_id') : null;
        if ($taxRateOwner === null || (string) $taxRateOwner !== (string) $businessId) {
            throw new \RuntimeException('product_tax_rates: referenced tax rate does not belong to this business.');
        }
    }

    /**
     * A journal_line's own tenant scoping (CHILD_SCOPED_MODELS, keyed via
     * journal_header_id) only proves the HEADER belongs to the caller — it
     * says nothing about the gl_account_id the line itself references. Without
     * this, a compromised or buggy device could post a debit/credit against
     * another business's GL account by id, corrupting that business's
     * balance. Unlike the soft accounting-quality checks below, this is a
     * tenant-isolation invariant a legitimate client can never violate, so
     * it throws — matching assertOwnership()'s existing severity for the
     * same kind of cross-tenant violation.
     */
    protected function assertAccountOwnedByJournalBusiness(?string $journalHeaderId, ?string $glAccountId, string $table): void
    {
        if (! $journalHeaderId || ! $glAccountId) {
            return;
        }

        $headerBusinessId = JournalHeader::where('id', $journalHeaderId)->value('business_id');
        $accountBusinessId = GlAccount::where('id', $glAccountId)->value('business_id');

        if ($headerBusinessId !== null && $accountBusinessId !== null && (string) $headerBusinessId !== (string) $accountBusinessId) {
            throw new \RuntimeException("{$table}: referenced gl_account does not belong to this business.");
        }
    }

    /**
     * Soft, log-only re-validation of a client-posted journal — balance and
     * period-closed. Deliberately never throws: these are accounting-quality
     * signals for manual follow-up, not tenant-isolation invariants, and a
     * false positive (e.g. a decimal rounding edge case) must never turn a
     * whole push group's worth of sibling records into a stuck, endlessly
     * retried sync error (see SyncController::push()'s per-group rollback).
     * Client-side JournalService is expected to already guarantee both of
     * these before it ever writes 'posted' locally — this just catches drift
     * or a client bug rather than trusting the payload blindly.
     */
    protected function checkClientJournalIntegrity(?JournalHeader $header): void
    {
        if (! $header) {
            return;
        }

        try {
            $totals = $header->lines()->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();
            if (abs((float) $totals->d - (float) $totals->c) >= 0.005) {
                Log::warning("Accounting: client-posted journal {$header->id} ({$header->journal_number}) does not balance — debit {$totals->d} vs credit {$totals->c}. Needs manual review.");
            }

            if (AccountingPeriod::isClosedFor($header->business_id, $header->trans_date->toDateString())) {
                Log::warning("Accounting: client-posted journal {$header->id} ({$header->journal_number}) is dated inside a closed accounting period ({$header->trans_date->toDateString()}). Needs manual review.");
            }
        } catch (\Throwable $e) {
            Log::warning("Accounting: failed to re-validate client-posted journal {$header->id}: {$e->getMessage()}");
        }
    }

    /**
     * product_sellable_locations uses a composite (product_id, location_id)
     * key instead of a single uuid, so it can't share the generic
     * child-table path above — same reason as product_tax_rates.
     */
    protected function assertProductSellableLocationOwnership(string $uuid, array $payload, ?string $businessId): void
    {
        if (empty($businessId)) {
            throw new \RuntimeException('product_sellable_locations: business_id is required to sync this record.');
        }

        $parts = explode('|', $uuid);
        $productId = count($parts) === 2 ? $parts[0] : ($payload['product_id'] ?? '');
        $locationId = count($parts) === 2 ? $parts[1] : ($payload['location_id'] ?? '');

        $productOwner = $productId ? Product::where('id', $productId)->value('business_id') : null;
        if ($productOwner === null || (string) $productOwner !== (string) $businessId) {
            throw new \RuntimeException('product_sellable_locations: referenced product does not belong to this business.');
        }

        $locationOwner = $locationId ? Location::where('id', $locationId)->value('business_id') : null;
        if ($locationOwner === null || (string) $locationOwner !== (string) $businessId) {
            throw new \RuntimeException('product_sellable_locations: referenced location does not belong to this business.');
        }
    }

    protected function handleUpsert(string $table, string $uuid, array $payload, bool $trusted = true, ?User $actingUser = null): void
    {
        switch ($table) {
            case 'locations':
                Location::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'parent_id' => $payload['parent_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'type' => $payload['type'] ?? 'shop',
                        'address' => $payload['address'] ?? null,
                        'phone' => $payload['phone'] ?? null,
                        'email' => $payload['email'] ?? null,
                        'can_sell' => $payload['can_sell'] ?? true,
                        'can_receive' => $payload['can_receive'] ?? true,
                        'is_active' => $payload['is_active'] ?? true,
                    ]
                );
                break;

            case 'product_stock':
                ProductStock::updateOrCreate(
                    ['product_id' => $payload['product_id'] ?? $uuid, 'location_id' => $payload['location_id'] ?? ''],
                    [
                        'id' => $uuid,
                        'quantity' => $payload['quantity'] ?? 0,
                        'reserved_quantity' => $payload['reserved_quantity'] ?? 0,
                        'in_transit_quantity' => $payload['in_transit_quantity'] ?? 0,
                        'low_stock_threshold' => $payload['low_stock_threshold'] ?? null,
                        'price_override' => $payload['price_override'] ?? null,
                    ]
                );
                break;

            case 'product_variant_stock':
                ProductVariantStock::updateOrCreate(
                    ['variant_id' => $payload['variant_id'] ?? $uuid, 'location_id' => $payload['location_id'] ?? ''],
                    [
                        'id' => $uuid,
                        'quantity' => $payload['quantity'] ?? 0,
                        'reserved_quantity' => $payload['reserved_quantity'] ?? 0,
                    ]
                );
                break;

            case 'stock_transfers':
                $existingTransfer = StockTransfer::find($uuid);
                $currentTransferStatus = $existingTransfer?->status;
                $incomingTransferStatus = $payload['status'] ?? 'pending';

                if (! StockTransfer::isValidTransition($currentTransferStatus, $incomingTransferStatus)) {
                    throw new \RuntimeException(
                        "Invalid stock transfer transition: '{$currentTransferStatus}' -> '{$incomingTransferStatus}'"
                    );
                }

                // A transfer leaving 'pending' into 'approved' was
                // previously accepted from any device with zero
                // authorization check: the transition-shape guard above only
                // validates the shape, not who's allowed to make it. Same
                // self-approval gap as the requisition/stocktake gates
                // elsewhere in this method, closed the same way, behind the
                // stock.transfer.approve permission that was already in the
                // catalogue but never wired to an enforcement point. Deliberately
                // does NOT gate 'pending' -> 'in_transit' — direct dispatch
                // without a separate approval step is an existing, intended
                // workflow (see SyncStockTransferTransitionTest's "dispatch is
                // allowed directly from pending"); only an explicit 'approved'
                // claim is gated.
                if (! $trusted
                    && $currentTransferStatus === 'pending'
                    && $incomingTransferStatus === 'approved') {
                    $transferBusinessId = $payload['business_id'] ?? $existingTransfer?->business_id;
                    $actingRole = $actingUser?->getRoleNames()->first();

                    if (! app(BackOfficeAuthorizer::class)->can($transferBusinessId, $actingRole, BackOfficePermission::STOCK_TRANSFER_APPROVE)) {
                        throw new \RuntimeException('stock_transfers: approving a transfer requires the stock.transfer.approve permission.');
                    }
                }

                StockTransfer::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? $existingTransfer?->business_id,
                        'transfer_number' => $payload['transfer_number'] ?? '',
                        'from_location_id' => $payload['from_location_id'] ?? null,
                        'to_location_id' => $payload['to_location_id'] ?? null,
                        'status' => $incomingTransferStatus,
                        'notes' => $payload['notes'] ?? null,
                        'requested_by_user_id' => $payload['requested_by_user_id'] ?? null,
                        'approved_by_user_id' => $payload['approved_by_user_id'] ?? null,
                        'approved_at' => $payload['approved_at'] ?? null,
                        'dispatched_at' => $payload['dispatched_at'] ?? null,
                        'received_at' => $payload['received_at'] ?? null,
                    ]
                );
                break;

            case 'approval_requests':
                $existingApprovalRequest = ApprovalRequest::find($uuid);
                $currentApprovalStatus = $existingApprovalRequest?->status;
                $incomingApprovalStatus = $payload['status'] ?? 'pending';

                if (! ApprovalRequest::isValidTransition($currentApprovalStatus, $incomingApprovalStatus)) {
                    throw new \RuntimeException(
                        "Invalid approval request transition: '{$currentApprovalStatus}' -> '{$incomingApprovalStatus}'"
                    );
                }

                // Same enforcement as ApprovalService::resolve() (separation
                // of duties + rule-based required-role), applied here too —
                // a device can resolve an approval_requests row through this
                // generic sync-push path directly (the till's own PIN-
                // approved/queued flows both write here), completely
                // bypassing ApprovalService::resolve(), which only the
                // BackOffice web controller ever calls. Without this, the
                // guard added there closes the BackOffice route but leaves
                // this one wide open — the same class of bypass every other
                // escalation gate in this file exists to close. $actingUser
                // (the device's own authenticated identity), never a
                // payload-claimed approver_user_id, decides who's deciding.
                if (! $trusted && $currentApprovalStatus === 'pending' && in_array($incomingApprovalStatus, ['approved', 'rejected'], true)) {
                    $applicableRule = $existingApprovalRequest->rule_set_id
                        ? app(ApprovalRuleEngine::class)->findApplicableRule(
                            ApprovalRuleSet::find($existingApprovalRequest->rule_set_id),
                            ['amount' => (float) ($existingApprovalRequest->estimated_value ?? 0)],
                            $existingApprovalRequest->current_level ?? 1,
                        )
                        : null;

                    if (! $actingUser || ! app(ApprovalRuleEngine::class)->canApprove($existingApprovalRequest->business_id, $actingUser->id, $existingApprovalRequest, $applicableRule)) {
                        throw new \RuntimeException(
                            match (true) {
                                $applicableRule?->approval_group_id !== null => 'approval_requests: deciding this stage requires a member of the assigned approver group.',
                                $applicableRule?->required_role !== null => "approval_requests: deciding this request requires {$applicableRule->required_role} authority or higher.",
                                default => 'approval_requests: you cannot approve or reject your own request.',
                            }
                        );
                    }
                }

                ApprovalRequest::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'subject_type' => $payload['subject_type'] ?? '',
                        'subject_id' => $payload['subject_id'] ?? null,
                        'action' => $payload['action'] ?? '',
                        'requested_by_user_id' => $payload['requested_by_user_id'] ?? null,
                        'status' => $incomingApprovalStatus,
                        'approver_user_id' => $payload['approver_user_id'] ?? null,
                        'approved_at' => $payload['approved_at'] ?? null,
                        'reason' => $payload['reason'] ?? null,
                        // Accept either a raw map (normal case: the outer HTTP
                        // JSON body already encodes it) or a pre-encoded JSON
                        // string, so a double-encode on either side degrades
                        // gracefully instead of corrupting the array cast.
                        'payload_json' => is_string($payload['payload_json'] ?? null)
                            ? json_decode($payload['payload_json'], true)
                            : ($payload['payload_json'] ?? null),
                        // ── Enterprise approval engine fields (schema v62) ─────
                        'rule_set_id' => $payload['rule_set_id'] ?? null,
                        'sla_due_at' => $payload['sla_due_at'] ?? null,
                        'priority' => $payload['priority'] ?? 'normal',
                        'current_level' => $payload['current_level'] ?? 1,
                        'max_level' => $payload['max_level'] ?? 1,
                        'estimated_value' => $payload['estimated_value'] ?? null,
                        'branch_id' => $payload['branch_id'] ?? null,
                        'is_delegated' => $payload['is_delegated'] ?? false,
                        'delegated_from_user_id' => $payload['delegated_from_user_id'] ?? null,
                    ]
                );
                break;

            case 'stock_transfer_items':
                StockTransferItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'stock_transfer_id' => $payload['stock_transfer_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'variant_id' => $payload['variant_id'] ?? null,
                        'product_name' => $payload['product_name'] ?? '',
                        'qty_requested' => $payload['qty_requested'] ?? 0,
                        'qty_sent' => $payload['qty_sent'] ?? 0,
                        'qty_received' => $payload['qty_received'] ?? 0,
                        'notes' => $payload['notes'] ?? null,
                        // GLS·03
                        'sheet_lot_id' => $payload['sheet_lot_id'] ?? null,
                    ]
                );
                break;

            case 'requisitions':
                $currentRequisitionStatus = Requisition::where('id', $uuid)->value('status');
                $incomingRequisitionStatus = $payload['status'] ?? 'pending';

                if (! Requisition::isValidTransition($currentRequisitionStatus, $incomingRequisitionStatus)) {
                    throw new \RuntimeException(
                        "Invalid requisition transition: '{$currentRequisitionStatus}' -> '{$incomingRequisitionStatus}'"
                    );
                }

                Requisition::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'requisition_number' => $payload['requisition_number'] ?? '',
                        'location_id' => $payload['location_id'] ?? null,
                        'purpose' => $payload['purpose'] ?? 'general',
                        'project_id' => $payload['project_id'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'status' => $incomingRequisitionStatus,
                        'requested_by_user_id' => $payload['requested_by_user_id'] ?? null,
                        'approved_by_user_id' => $payload['approved_by_user_id'] ?? null,
                        'approved_at' => $payload['approved_at'] ?? null,
                        'issued_by_user_id' => $payload['issued_by_user_id'] ?? null,
                        'issued_at' => $payload['issued_at'] ?? null,
                    ]
                );
                break;

            case 'requisition_items':
                RequisitionItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'requisition_id' => $payload['requisition_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'product_name' => $payload['product_name'] ?? '',
                        'quantity_requested' => $payload['quantity_requested'] ?? 0,
                        'quantity_issued' => $payload['quantity_issued'] ?? 0,
                        'unit_cost' => $payload['unit_cost'] ?? null,
                    ]
                );
                break;

            case 'projects':
                Project::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'reference' => $payload['reference'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'budget' => $payload['budget'] ?? null,
                        'status' => $payload['status'] ?? 'active',
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                        'closed_at' => $payload['closed_at'] ?? null,
                    ]
                );
                break;

            case 'project_milestones':
                ProjectMilestone::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'project_id' => $payload['project_id'] ?? null,
                        'title' => $payload['title'] ?? '',
                        'target_date' => $payload['target_date'] ?? null,
                    ]
                );
                break;

            case 'milestone_tasks':
                MilestoneTask::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'milestone_id' => $payload['milestone_id'] ?? null,
                        'title' => $payload['title'] ?? '',
                        'is_done' => $payload['is_done'] ?? false,
                        'done_at' => $payload['done_at'] ?? null,
                    ]
                );
                break;

            case 'procurement_budgets':
                ProcurementBudget::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'period_start' => $payload['period_start'] ?? null,
                        'period_end' => $payload['period_end'] ?? null,
                        'amount' => $payload['amount'] ?? 0,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'sheet_lots':
                SheetLot::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'original_width' => $payload['original_width'] ?? null,
                        'original_height' => $payload['original_height'] ?? null,
                        'area' => $payload['area'] ?? 0,
                        'status' => $payload['status'] ?? 'available',
                        'received_by_user_id' => $payload['received_by_user_id'] ?? null,
                        // GLS·02
                        'parent_lot_id' => $payload['parent_lot_id'] ?? null,
                        'root_lot_id' => $payload['root_lot_id'] ?? null,
                        'display_code' => $payload['display_code'] ?? null,
                        'bin_location' => $payload['bin_location'] ?? null,
                        'unit_cost' => $payload['unit_cost'] ?? null,
                        'source_purchase_order_id' => $payload['source_purchase_order_id'] ?? null,
                        'reserved_for_type' => $payload['reserved_for_type'] ?? null,
                        'reserved_for_id' => $payload['reserved_for_id'] ?? null,
                        'reserved_until' => $payload['reserved_until'] ?? null,
                        'reserved_by_user_id' => $payload['reserved_by_user_id'] ?? null,
                        // GLS·03
                        'warehouse_bin_id' => $payload['warehouse_bin_id'] ?? null,
                    ]
                );
                break;

            case 'warehouse_bins':
                WarehouseBin::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'zone' => $payload['zone'] ?? null,
                        'rack' => $payload['rack'] ?? null,
                        'bay' => $payload['bay'] ?? null,
                        'position' => $payload['position'] ?? null,
                    ]
                );
                break;

            case 'sheet_cuts':
                SheetCut::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'sheet_lot_id' => $payload['sheet_lot_id'] ?? null,
                        'width' => $payload['width'] ?? 0,
                        'height' => $payload['height'] ?? 0,
                        'area' => $payload['area'] ?? 0,
                        'transaction_id' => $payload['transaction_id'] ?? null,
                        'user_id' => $payload['user_id'] ?? null,
                        'cut_at' => $payload['cut_at'] ?? now(),
                        // GLS·02
                        'result_kind' => $payload['result_kind'] ?? null,
                        'child_lot_id' => $payload['child_lot_id'] ?? null,
                        'reason' => $payload['reason'] ?? null,
                    ]
                );
                break;

            case 'sheet_loss_records':
                SheetLossRecord::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'sheet_lot_id' => $payload['sheet_lot_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'kind' => $payload['kind'] ?? 'cutting_waste',
                        'reason' => $payload['reason'] ?? 'other',
                        'width' => $payload['width'] ?? null,
                        'height' => $payload['height'] ?? null,
                        'area' => $payload['area'] ?? 0,
                        'unit_cost' => $payload['unit_cost'] ?? null,
                        'financial_impact' => $payload['financial_impact'] ?? 0,
                        'notes' => $payload['notes'] ?? null,
                        'photo_path' => $payload['photo_path'] ?? null,
                        'approval_request_id' => $payload['approval_request_id'] ?? null,
                        'reported_by_user_id' => $payload['reported_by_user_id'] ?? null,
                        'approved_by_user_id' => $payload['approved_by_user_id'] ?? null,
                        'created_at' => $payload['created_at'] ?? now(),
                    ]
                );
                break;

            case 'currencies':
                Currency::updateOrCreate(
                    ['code' => $uuid],
                    [
                        'name' => $payload['name'] ?? $uuid,
                        'symbol' => $payload['symbol'] ?? $uuid,
                        'decimal_places' => $payload['decimal_places'] ?? 2,
                        'is_base' => $payload['is_base'] ?? false,
                        'is_enabled' => $payload['is_enabled'] ?? true,
                    ]
                );
                break;

            case 'businesses':
                // Devices are expected to always send businessSyncPayload()'s
                // full field snapshot — its own doc comment says this exact
                // "missing key means reset to null/default" footgun has
                // already silently wiped fiscalisation_enabled/tin (and
                // separately day_shift_start/night_shift_start) in
                // production, twice, before that helper existed. But that
                // fix only ever lived on the Flutter side; this endpoint is
                // the generic device-sync entry point, and nothing stops a
                // different/older/malformed client from doing the exact
                // same thing again — confirmed live: a push containing
                // nothing but a phone number change silently reset
                // fiscalisation_enabled to false and tin to null for a real
                // fiscalised business. Preserve whatever the payload omits
                // instead of defaulting it, so an incomplete payload from
                // ANY client can no longer regress ZIMRA fiscal compliance
                // or the shift-window settings.
                $existingBusiness = Business::find($uuid);
                $preserve = fn (string $key, $default = null) => array_key_exists($key, $payload)
                    ? $payload[$key]
                    : ($existingBusiness?->{$key} ?? $default);

                Business::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'name' => $preserve('name', ''),
                        'address' => $preserve('address'),
                        'phone' => $preserve('phone'),
                        'email' => $preserve('email'),
                        'tax_number' => $payload['vat_number'] ?? $payload['tax_number'] ?? $existingBusiness?->tax_number,
                        'tin' => $preserve('tin'),
                        'currency_code' => $payload['base_currency_code'] ?? $existingBusiness?->currency_code ?? 'USD',
                        // logo_path/primary_color/letterhead_path/footer_path are
                        // deliberately absent here: they're owned by the
                        // 'business_branding' sync record and their own upload
                        // endpoints, not this generic device push. Applying a
                        // device's local path (an opaque on-device file path) here
                        // would overwrite the server's real public URL on every sync.
                        // footer_text is plain text, safe to preserve like any other field.
                        'footer_text' => $preserve('footer_text'),
                        'metadata' => $preserve('metadata'),
                        'fiscalisation_enabled' => $preserve('fiscalisation_enabled', false),
                        'day_shift_start' => $preserve('day_shift_start'),
                        'night_shift_start' => $preserve('night_shift_start'),
                    ]
                );
                break;

            case 'users':
                $this->syncUser($uuid, $payload, $trusted, $actingUser);
                break;

            case 'accounting_settings':
                // Flutter-first activation: an authorized till enables
                // accounting offline (AccountingSettingsService) and pushes
                // the row up; the server coordinates it, never gates on it.
                // record_uuid IS the business id (see
                // Business::publishAccountingSettingsSyncRecord()).
                $businessId = $payload['business_id'] ?? null;

                if (empty($businessId) || (string) $businessId !== (string) $uuid) {
                    throw new \RuntimeException('accounting_settings: business_id must match the record id.');
                }

                $business = Business::find($uuid);

                if (! $business) {
                    throw new \RuntimeException('accounting_settings: unknown business.');
                }

                if (! $trusted) {
                    $this->requireOwnerOrManager($actingUser, 'accounting_settings: changing accounting activation');
                }

                // Last-write-wins across offline devices: a stale activation
                // arriving after a newer one must not flap the flags back.
                $incomingAt = isset($payload['updated_at']) ? Carbon::parse($payload['updated_at']) : null;

                if ($incomingAt && $business->updated_at && $incomingAt->lt($business->updated_at)) {
                    Log::debug("Sync: ignoring stale accounting_settings for business {$uuid}.");

                    break;
                }

                $business->update([
                    'accounting_go_live_date' => array_key_exists('accounting_go_live_date', $payload)
                        ? $payload['accounting_go_live_date']
                        : $business->accounting_go_live_date,
                    'client_gl_posting_enabled_at' => array_key_exists('client_gl_posting_enabled_at', $payload)
                        ? $payload['client_gl_posting_enabled_at']
                        : $business->client_gl_posting_enabled_at,
                ]);

                // Fan out to every other till so the whole fleet converges
                // on the same activation without any device polling for it.
                $business->refresh()->publishAccountingSettingsSyncRecord();
                break;

            case 'categories':
                Category::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'parent_id' => $payload['parent_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'color' => $payload['color'] ?? null,
                        'icon' => $payload['icon'] ?? null,
                        'sort_order' => $payload['sort_order'] ?? 0,
                        'is_active' => $payload['is_active'] ?? true,
                    ]
                );
                break;

            case 'units_of_measure':
                UnitOfMeasure::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'is_active' => $payload['is_active'] ?? true,
                    ]
                );
                break;

            case 'tax_rates':
                TaxRate::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'rate' => $payload['rate'] ?? 0,
                        'type' => $payload['type'] ?? 'exclusive',
                        'is_compound' => $payload['is_compound'] ?? false,
                        'is_default' => $payload['is_default'] ?? false,
                        'is_active' => $payload['is_active'] ?? true,
                    ]
                );
                break;

            case 'exchange_rates':
                // A rate change must be backed by an approved
                // change_exchange_rate approval_requests row before it can
                // become active — see hasApprovedRequest() above. The
                // Flutter client always generates the exchange_rates row's
                // own uuid as the approval's subject_id (rates_tab_screen.dart,
                // approval_resolution.dart), so a direct subject_id match is
                // sufficient here; no approval_request_id payload field
                // needed. A rate change is always a brand-new uuid, so this
                // still only ever gates first-insert of a given rate id — the
                // one legitimate update to an existing row is
                // ApprovalService::applyApprovedAction() closing out the
                // previously-current row's valid_until (FX·06 audit history),
                // which is server-authored ($trusted stays true there) and
                // so never hits this untrusted-payload branch at all.
                if (! $trusted && ! ExchangeRate::where('id', $uuid)->exists()
                    && ! $this->hasApprovedRequest($payload['business_id'] ?? null, 'ExchangeRate', $uuid, ['change_exchange_rate'], $payload)) {
                    throw new \RuntimeException('exchange_rates: a rate change requires an approved approval request.');
                }

                ExchangeRate::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'from_currency' => $payload['from_currency'] ?? '',
                        'to_currency' => $payload['to_currency'] ?? '',
                        'rate' => $payload['rate'] ?? 1,
                        'source' => $payload['source'] ?? 'manual',
                        'set_by_user_id' => $payload['set_by_user_id'] ?? null,
                        'locked' => $payload['locked'] ?? false,
                        'valid_from' => $payload['valid_from'] ?? now(),
                        'valid_until' => $payload['valid_until'] ?? null,
                    ]
                );
                break;

            case 'products':
                $productExisted = Product::where('id', $uuid)->exists();
                $openingStock = (float) ($payload['stock_quantity'] ?? 0);

                $product = Product::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'category_id' => $payload['category_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'item_type' => $payload['item_type'] ?? 'product',
                        'sku' => $payload['sku'] ?? null,
                        'barcode' => $payload['barcode'] ?? null,
                        'price' => $payload['price'] ?? 0,
                        'min_price' => $payload['min_price'] ?? null,
                        'discount_percent' => $payload['discount_percent'] ?? null,
                        'cost_price' => $payload['cost_price'] ?? 0,
                        'deposit_amount' => $payload['deposit_amount'] ?? null,
                        'unit' => $payload['unit'] ?? 'piece',
                        'sheet_width' => $payload['sheet_width'] ?? null,
                        'sheet_height' => $payload['sheet_height'] ?? null,
                        // GLS·02 — every 'products' upsert is a full-row
                        // replace (see this case's other `?? default`
                        // fields), so a payload built before these columns
                        // existed — or from a call site that forgot them —
                        // would otherwise silently wipe a sheet product's
                        // cutting rules on every unrelated receive/sale.
                        'sheet_min_usable_width' => $payload['sheet_min_usable_width'] ?? null,
                        'sheet_min_usable_height' => $payload['sheet_min_usable_height'] ?? null,
                        'sheet_kerf_width' => $payload['sheet_kerf_width'] ?? null,
                        'sheet_cutting_charge' => $payload['sheet_cutting_charge'] ?? null,
                        'sheet_allow_rotate' => $payload['sheet_allow_rotate'] ?? true,
                        'track_stock' => $payload['track_stock'] ?? true,
                        // stock_quantity is accepted from payload for initial setup.
                        // It will be overridden below if movements exist (multi-device safe).
                        'stock_quantity' => $payload['stock_quantity'] ?? 0,
                        'low_stock_threshold' => $payload['low_stock_threshold'] ?? 5,
                        'image_path' => $payload['image_path'] ?? null,
                        'expiry_date' => $payload['expiry_date'] ?? null,
                        'is_active' => $payload['is_active'] ?? true,
                        'is_taxable' => $payload['is_taxable'] ?? true,
                    ]
                );
                // First time this product is created with an opening quantity: give it
                // a ledger entry, same as every other stock change, so take-on has an
                // audit trail instead of being a bare column write nothing can trace.
                // An optional location_id (set by location-aware callers, e.g. the
                // BackOffice create form) attributes the opening quantity to that
                // location too, instead of leaving it in the flat total only.
                if (! $productExisted && $openingStock != 0) {
                    $openingMovement = StockMovement::create([
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'product_id' => $uuid,
                        'type' => 'opening_stock',
                        'quantity_change' => $openingStock,
                        'unit_cost' => $payload['cost_price'] ?? 0,
                        'reason' => 'Opening stock (take-on)',
                        'user_id' => $payload['user_id'] ?? null,
                    ]);
                    // Record the take-on value in the books too — see
                    // ProductOpeningStockPostingService for why this ledger
                    // entry alone previously left the balance sheet blind to
                    // any stock a business took on when it started using the
                    // system (e.g. via a sheet/CSV import).
                    app(ProductOpeningStockPostingService::class)->recordTakeOn($openingMovement);
                }
                // If any movements exist for this product, recompute from the ledger
                // so concurrent pushes from multiple devices converge correctly.
                $this->recomputeProductStock($uuid);
                if (! empty($payload['location_id'])) {
                    $this->recomputeLocationStock($uuid, $payload['location_id']);
                }
                break;

            case 'bundles':
                Bundle::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'description' => $payload['description'] ?? null,
                        'is_active' => $payload['is_active'] ?? true,
                    ]
                );
                break;

            case 'bundle_items':
                BundleItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'bundle_id' => $payload['bundle_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'quantity' => $payload['quantity'] ?? 1,
                        'sort_order' => $payload['sort_order'] ?? 0,
                    ]
                );
                break;

            case 'product_container_links':
                ProductContainerLink::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'beverage_product_id' => $payload['beverage_product_id'] ?? null,
                        'container_product_id' => $payload['container_product_id'] ?? null,
                        'quantity_per_unit' => $payload['quantity_per_unit'] ?? 1,
                    ]
                );
                break;

            case 'product_units':
                ProductUnit::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'product_id' => $payload['product_id'] ?? null,
                        'unit_name' => $payload['unit_name'] ?? '',
                        'conversion_factor' => $payload['conversion_factor'] ?? 1,
                        'is_base_unit' => $payload['is_base_unit'] ?? false,
                    ]
                );
                break;

            case 'product_price_tiers':
                ProductPriceTier::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'product_id' => $payload['product_id'] ?? null,
                        'min_qty' => $payload['min_qty'] ?? 0,
                        'unit_price' => $payload['unit_price'] ?? 0,
                    ]
                );
                break;

            case 'product_tax_rates':
                // uuid is "productId|taxRateId" composite key
                $parts = explode('|', $uuid);
                if (count($parts) === 2) {
                    ProductTaxRate::firstOrCreate([
                        'product_id' => $parts[0],
                        'tax_rate_id' => $parts[1],
                    ]);
                } else {
                    ProductTaxRate::firstOrCreate([
                        'product_id' => $payload['product_id'] ?? '',
                        'tax_rate_id' => $payload['tax_rate_id'] ?? '',
                    ]);
                }
                break;

            case 'product_sellable_locations':
                // uuid is "productId|locationId" composite key
                $parts = explode('|', $uuid);
                if (count($parts) === 2) {
                    ProductSellableLocation::firstOrCreate([
                        'product_id' => $parts[0],
                        'location_id' => $parts[1],
                    ]);
                } else {
                    ProductSellableLocation::firstOrCreate([
                        'product_id' => $payload['product_id'] ?? '',
                        'location_id' => $payload['location_id'] ?? '',
                    ]);
                }
                break;

            case 'product_variants':
                ProductVariant::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'product_id' => $payload['product_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'price_modifier' => $payload['price_modifier'] ?? 0,
                        'stock_quantity' => $payload['stock_quantity'] ?? 0,
                        'barcode' => $payload['barcode'] ?? null,
                        'is_active' => $payload['is_active'] ?? true,
                    ]
                );
                break;

            case 'customers':
                Customer::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'phone' => $payload['phone'] ?? null,
                        'email' => $payload['email'] ?? null,
                        'address' => $payload['address'] ?? null,
                        'photo_path' => $payload['photo_path'] ?? null,
                        // loyalty_points and credit_balance accepted from payload for initial setup.
                        // They will be overridden below if ledger rows exist (multi-device safe).
                        'loyalty_points' => $payload['loyalty_points'] ?? 0,
                        'credit_balance' => $payload['credit_balance'] ?? 0,
                        'credit_limit' => $payload['credit_limit'] ?? 0,
                        'is_tax_exempt' => $payload['is_tax_exempt'] ?? false,
                        'group' => $payload['group'] ?? 'regular',
                    ]
                );
                $this->recomputeCustomerBalances($uuid);
                break;

            case 'transactions':
                $existingTx = Transaction::where('id', $uuid)->first();
                $txExists = $existingTx !== null;
                $previousStatus = $existingTx?->status;
                $incomingStatus = $payload['status'] ?? 'completed';

                // Untrusted (device-pushed) writes that move a sale into a
                // void/refund status must be backed by an approved
                // approval_requests row — see hasApprovedRequest() above.
                // $previousStatus !== $incomingStatus also covers a brand
                // new transaction born already voided/refunded (an offline
                // void/refund of a sale that had never synced before),
                // since $previousStatus is null there.
                if (! $trusted && $previousStatus !== $incomingStatus) {
                    $gatedActions = match ($incomingStatus) {
                        'voided' => ['void_transaction'],
                        'refunded', 'partial_refund' => ['refund_transaction'],
                        default => null,
                    };

                    if ($gatedActions && ! $this->hasApprovedRequest($payload['business_id'] ?? null, 'Transaction', $uuid, $gatedActions, $payload)) {
                        throw new \RuntimeException("transactions: {$incomingStatus} requires an approved approval request.");
                    }
                }

                $txData = [
                    'business_id' => $payload['business_id'] ?? null,
                    'location_id' => $payload['location_id'] ?? null,
                    'user_id' => $payload['user_id'] ?? null,
                    'customer_id' => $payload['customer_id'] ?? null,
                    'client_name' => $payload['client_name'] ?? null,
                    'subtotal' => $payload['subtotal'] ?? 0,
                    'tax_total' => $payload['tax_total'] ?? 0,
                    'discount_total' => $payload['discount_total'] ?? 0,
                    'deposit_total' => $payload['deposit_total'] ?? 0,
                    'surcharge_total' => $payload['surcharge_total'] ?? 0,
                    'total' => $payload['total'] ?? 0,
                    'base_currency' => $payload['base_currency'] ?? 'USD',
                    'status' => $incomingStatus,
                    'sale_number' => $payload['sale_number'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'void_reason' => $payload['void_reason'] ?? null,
                ];
                $tx = Transaction::updateOrCreate(['id' => $uuid], $txData);

                if (! $txExists && isset($payload['created_at'])) {
                    // Preserve the device's sale timestamp on first insert.
                    // Setting created_at inside the updateOrCreate() payload
                    // above does NOT work — Eloquent's automatic timestamp
                    // management overwrites it with "now" during the insert
                    // regardless of what's in the attributes array. A plain
                    // Eloquent save() with $timestamps disabled doesn't work
                    // either: Model::getDates() excludes created_at/updated_at
                    // entirely once usesTimestamps() is false, so the value
                    // skips Carbon casting both in and out — an ISO 8601
                    // string (as the device sends) fails outright against
                    // MySQL's native datetime format, and even a
                    // MySQL-shaped string would come back as a plain PHP
                    // string on every future read, not a Carbon instance.
                    // A raw query update sidesteps Eloquent entirely for
                    // just this column. Getting this right matters beyond
                    // accounting: reports that group sales by date
                    // (ReportsController's daily breakdown,
                    // SalePostingService's trans_date) would otherwise
                    // silently use "when this synced" instead of "when the
                    // sale happened" for any till that syncs late.
                    DB::table('transactions')->where('id', $uuid)->update([
                        'created_at' => Carbon::parse($payload['created_at'])->format('Y-m-d H:i:s'),
                    ]);
                    $tx->refresh();
                }

                // Queue ZIMRA fiscalisation whenever a transaction becomes
                // completed — either brand new, or transitioning from another
                // status (a layby that's just been paid off in full is the
                // main case: it already existed as 'layby', so a txExists-only
                // check never caught it becoming a real completed sale).
                // queueFiscalisation() is safe to call on an
                // already-fiscalised transaction — it no-ops immediately once
                // fiscal_status is 'fiscalised' or ZIMRA has already accepted
                // the receipt — so this only ever queues real transitions,
                // never a duplicate submission.
                if ($tx->status === 'completed' && $previousStatus !== 'completed') {
                    app(ZimraSalesService::class)->queueFiscalisation($tx);
                }

                // Accounting (Phase 11b) — same transition-based trigger as
                // ZIMRA above, plus voiding, which needs to reverse whatever
                // was already posted rather than post something new. No-ops
                // quietly if this sale's items/payments haven't all synced
                // yet; the accounting:post-pending-sales sweep catches it.
                if ($tx->status !== $previousStatus) {
                    app(SalePostingService::class)->postIfReady($tx);
                }
                break;

            case 'transaction_items':
                TransactionItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'transaction_id' => $payload['transaction_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'variant_id' => $payload['variant_id'] ?? null,
                        'product_name' => $payload['product_name'] ?? '',
                        'quantity' => $payload['quantity'] ?? 0,
                        'unit_price' => $payload['unit_price'] ?? 0,
                        'discount' => $payload['discount'] ?? 0,
                        'tax_amount' => $payload['tax_amount'] ?? 0,
                        'line_total' => $payload['line_total'] ?? 0,
                        'notes' => $payload['notes'] ?? null,
                    ]
                );

                // The transaction row itself may well have synced first with
                // its items still missing — retry posting now that one more
                // piece has landed (see the 'transactions' case above).
                if ($parentTx = Transaction::find($payload['transaction_id'] ?? null)) {
                    app(SalePostingService::class)->postIfReady($parentTx);
                }
                break;

            case 'transaction_taxes':
                TransactionTax::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'transaction_id' => $payload['transaction_id'] ?? null,
                        'tax_rate_id' => $payload['tax_rate_id'] ?? null,
                        'tax_name' => $payload['tax_name'] ?? '',
                        'rate_snapshot' => $payload['rate_snapshot'] ?? 0,
                        'taxable_amount' => $payload['taxable_amount'] ?? 0,
                        'tax_amount' => $payload['tax_amount'] ?? 0,
                    ]
                );
                break;

            case 'payments':
                Payment::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'transaction_id' => $payload['transaction_id'] ?? null,
                        'method' => $payload['method'] ?? 'cash',
                        'amount' => $payload['amount'] ?? 0,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'exchange_rate_used' => $payload['exchange_rate_used'] ?? 1,
                        'base_equivalent' => $payload['base_equivalent'] ?? 0,
                        'change_given' => $payload['change_given'] ?? 0,
                        'reference' => $payload['reference'] ?? null,
                        'rounding_adjustment' => $payload['rounding_adjustment'] ?? 0,
                        'bank_account_id' => $payload['bank_account_id'] ?? null,
                        'pop_attachment_path' => $payload['pop_attachment_path'] ?? null,
                    ]
                );

                // Same retry-on-arrival reasoning as transaction_items above.
                if ($parentTx = Transaction::find($payload['transaction_id'] ?? null)) {
                    app(SalePostingService::class)->postIfReady($parentTx);
                }
                break;

            case 'stock_movements':
                // STK·03 — "a requisition alone must never remove stock,"
                // and issuing one is gated on approval. Unlike void/refund/
                // exchange-rate changes, a requisition's approval lives on
                // its own `status` column rather than the generic
                // `approval_requests` table (see Requisition::isApproved()),
                // so this can't reuse hasApprovedRequest() — but the
                // principle is identical: an untrusted device claiming a
                // 'requisition_issue' stock movement must point at a
                // requisition that's actually been approved, not just
                // trust whatever the Flutter UI's own button-visibility
                // gate would normally have enforced. Without this, a
                // device that talks to the API directly (bypassing the
                // app) could deduct real stock against a requisition
                // that was never approved.
                if (! $trusted && ($payload['type'] ?? null) === 'requisition_issue') {
                    $requisitionId = $payload['reference_id'] ?? null;
                    $requisition = $requisitionId ? Requisition::find($requisitionId) : null;

                    if (! $requisition || ! $requisition->isApproved()) {
                        throw new \RuntimeException('stock_movements: requisition_issue requires an approved requisition.');
                    }
                }

                $movement = StockMovement::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'to_location_id' => $payload['to_location_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'type' => $payload['type'] ?? 'adjustment',
                        'quantity_change' => $payload['quantity_change'] ?? 0,
                        'unit_cost' => $payload['unit_cost'] ?? null,
                        'running_avg_cost' => $payload['running_avg_cost'] ?? null,
                        'reason' => $payload['reason'] ?? null,
                        'reference_id' => $payload['reference_id'] ?? null,
                        'attachment_path' => $payload['attachment_path'] ?? null,
                        'user_id' => $payload['user_id'] ?? null,
                    ]
                );
                // Recompute the product's stock from the full movement ledger.
                // Both must run: recomputeLocationStock keeps the per-location ledger
                // (product_stock) accurate, and recomputeProductStock keeps the legacy
                // flat field (products.stock_quantity) as the cross-location total in
                // sync with it. They are two views of the same ledger, never independent
                // writes — letting either drift is what caused inventory reads to disagree.
                if (! empty($payload['product_id'])) {
                    $locationId = $payload['location_id'] ?? null;
                    if ($locationId) {
                        $this->recomputeLocationStock($payload['product_id'], $locationId);
                    }
                    $this->recomputeProductStock($payload['product_id']);
                }

                // Purchasing & Cash Vault Blueprint, part A — a 'receive'
                // movement that references a real PurchaseOrder (a known
                // supplier) gets a GRV and a GL posting; walk-in receiving
                // (reference_id null) is a no-op inside the service itself.
                // quantity_rejected/rejection_reason are payload-only (never
                // a stock_movements column — a rejected unit never entered
                // inventory in the first place), reported by the till's
                // receiving screen alongside the movement.
                app(GrvPostingService::class)->recordReceipt(
                    $movement,
                    (float) ($payload['quantity_rejected'] ?? 0),
                    $payload['rejection_reason'] ?? null,
                );

                // A 'stocktake' movement is one variance line from an
                // approved stock take — post its GL effect the same
                // tolerant-of-failure way as the GRV posting above.
                app(StockTakePostingService::class)->recordVariance($movement);

                // An 'opening_stock' movement here is a take-on figure set
                // (or corrected) via ProductsController::applyLocationBalance()
                // — the "Set Opening Balance" action and the CSV/sheet
                // re-import's quantity reconciliation. The 'products' case
                // above covers a brand-new product's own initial quantity;
                // this covers every later adjustment to a take-on figure.
                app(ProductOpeningStockPostingService::class)->recordTakeOn($movement);
                break;

            case 'loyalty_transactions':
                LoyaltyTransaction::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'customer_id' => $payload['customer_id'] ?? null,
                        'transaction_id' => $payload['transaction_id'] ?? null,
                        'points' => $payload['points'] ?? 0,
                        'type' => $payload['type'] ?? 'earn',
                        'note' => $payload['note'] ?? null,
                    ]
                );
                // Recompute customer loyalty_points from the full ledger.
                if (! empty($payload['customer_id'])) {
                    $this->recomputeCustomerBalances($payload['customer_id']);
                }
                break;

            case 'credit_transactions':
                $creditTransaction = CreditTransaction::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'customer_id' => $payload['customer_id'] ?? null,
                        'transaction_id' => $payload['transaction_id'] ?? null,
                        'amount' => $payload['amount'] ?? 0,
                        'type' => $payload['type'] ?? 'purchase',
                        'method' => $payload['method'] ?? null,
                        'reference' => $payload['reference'] ?? null,
                        'receipt_number' => $payload['receipt_number'] ?? null,
                        'bank_account_id' => $payload['bank_account_id'] ?? null,
                    ]
                );
                // Recompute customer credit_balance from the full ledger.
                if (! empty($payload['customer_id'])) {
                    $this->recomputeCustomerBalances($payload['customer_id']);
                }
                // The missing half of credit-sale accounting — see
                // CreditPaymentPostingService's doc comment. Only 'repayment'
                // rows post anything; the service itself no-ops otherwise.
                app(CreditPaymentPostingService::class)->postIfReady($creditTransaction);
                // A one-time opening balance also needs to land in the
                // formal books (if this business has any) — see
                // OpeningBalanceService's doc comment. The till-side ledger
                // update above already happened regardless of whether
                // accounting is switched on.
                if (($payload['type'] ?? null) === 'opening_balance' && ! empty($payload['customer_id'])) {
                    $businessId = Customer::where('id', $payload['customer_id'])->value('business_id');
                    if ($businessId) {
                        app(OpeningBalanceService::class)->recordCustomerOpeningBalance(
                            $businessId,
                            $payload['customer_id'],
                            (float) ($payload['amount'] ?? 0),
                            isset($payload['created_at']) ? Carbon::parse($payload['created_at'])->toDateString() : now()->toDateString(),
                            $payload['reference'] ?? null,
                        );
                    }
                }
                break;

            case 'container_deposit_ledger':
                // No denormalized balance to recompute — outstanding
                // containers and deposit liability are always derived live
                // from SUM() over this ledger by the reporting screen, so
                // there's nothing here that can drift out of sync.
                ContainerDepositLedger::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'container_product_id' => $payload['container_product_id'] ?? null,
                        'transaction_id' => $payload['transaction_id'] ?? null,
                        'quantity' => $payload['quantity'] ?? 0,
                        'deposit_amount_per_unit' => $payload['deposit_amount_per_unit'] ?? 0,
                        'type' => $payload['type'] ?? 'issue',
                        'refund_method' => $payload['refund_method'] ?? null,
                        'reason' => $payload['reason'] ?? null,
                        'user_id' => $payload['user_id'] ?? null,
                    ]
                );
                break;

            case 'change_owed_ledger':
                // Same convention as container_deposit_ledger: outstanding
                // owed change is always derived live via SUM() over this
                // ledger, grouped by transaction_id — nothing here to drift.
                ChangeOwedLedger::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'transaction_id' => $payload['transaction_id'] ?? null,
                        'amount' => $payload['amount'] ?? 0,
                        'currency_code' => $payload['currency_code'] ?? '',
                        'type' => $payload['type'] ?? 'issue',
                        'payment_method' => $payload['payment_method'] ?? null,
                        'reason' => $payload['reason'] ?? null,
                        'user_id' => $payload['user_id'] ?? null,
                    ]
                );
                break;

            case 'suppliers':
                Supplier::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'contact_name' => $payload['contact_name'] ?? null,
                        'phone' => $payload['phone'] ?? null,
                        'email' => $payload['email'] ?? null,
                        'address' => $payload['address'] ?? null,
                        'website' => $payload['website'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'tax_number' => $payload['tax_number'] ?? null,
                        'is_active' => $payload['is_active'] ?? true,
                        // AP module.
                        'supplier_code' => $payload['supplier_code'] ?? null,
                        'trading_name' => $payload['trading_name'] ?? null,
                        'currency_code' => $payload['currency_code'] ?? null,
                        'payment_terms_days' => $payload['payment_terms_days'] ?? 30,
                        'credit_limit' => $payload['credit_limit'] ?? null,
                        'category' => $payload['category'] ?? null,
                        'tax_status' => $payload['tax_status'] ?? 'standard',
                        'bank_name' => $payload['bank_name'] ?? null,
                        'bank_account_number' => $payload['bank_account_number'] ?? null,
                        'bank_branch' => $payload['bank_branch'] ?? null,
                        'control_account_id' => $payload['control_account_id'] ?? null,
                    ]
                );
                break;

            case 'goods_received_vouchers':
                // Flutter-first port — see this table's IMMUTABLE doc
                // comment. Laravel's own stock_movement-driven creation
                // (GrvPostingService.php) backs off once a business is cut
                // over to client posting, so this and that path never race
                // for the same business.
                GoodsReceivedVoucher::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'grv_number' => $payload['grv_number'] ?? '',
                        'purchase_order_id' => $payload['purchase_order_id'] ?? null,
                        'supplier_id' => $payload['supplier_id'] ?? null,
                        'received_date' => $payload['received_date'] ?? now()->toDateString(),
                    ]
                );
                break;

            case 'grv_items':
                GrvItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'grv_id' => $payload['grv_id'] ?? null,
                        'stock_movement_id' => $payload['stock_movement_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'product_name' => $payload['product_name'] ?? '',
                        'quantity_received' => $payload['quantity_received'] ?? 0,
                        'quantity_accepted' => $payload['quantity_accepted'] ?? 0,
                        'quantity_rejected' => $payload['quantity_rejected'] ?? 0,
                        'rejection_reason' => $payload['rejection_reason'] ?? null,
                        'unit_cost' => $payload['unit_cost'] ?? 0,
                    ]
                );
                break;

            case 'supplier_invoices':
                // Same fraud class as 'supplier_payments'/'assets' above — a
                // fabricated or prematurely-'posted' supplier invoice raises
                // a real Dr Inventory-or-Expense / Cr Accounts Payable
                // liability the instant it posts.
                if (! $trusted && ! ($actingUser?->hasRole(['business_owner', 'manager']) ?? false)) {
                    throw new \RuntimeException('supplier_invoices: recording a supplier invoice requires owner or manager access.');
                }

                SupplierInvoice::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'supplier_id' => $payload['supplier_id'] ?? null,
                        'purchase_order_id' => $payload['purchase_order_id'] ?? null,
                        'invoice_number' => $payload['invoice_number'] ?? '',
                        'invoice_date' => $payload['invoice_date'] ?? now()->toDateString(),
                        'due_date' => $payload['due_date'] ?? null,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'exchange_rate' => $payload['exchange_rate'] ?? 1,
                        'subtotal' => $payload['subtotal'] ?? 0,
                        'discount_total' => $payload['discount_total'] ?? 0,
                        'tax_total' => $payload['tax_total'] ?? 0,
                        'withholding_tax_total' => $payload['withholding_tax_total'] ?? 0,
                        'other_charges_total' => $payload['other_charges_total'] ?? 0,
                        // 'amount' is this table's pre-existing total column
                        // (see the 2026_09_06 migration) — Flutter's own
                        // SupplierInvoices.total maps onto it rather than
                        // renaming a column half the codebase still reads.
                        'amount' => $payload['total'] ?? 0,
                        'status' => $payload['status'] ?? 'draft',
                        'match_status' => $payload['match_status'] ?? 'not_applicable',
                        'description' => $payload['description'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                        'approved_by_user_id' => $payload['approved_by_user_id'] ?? null,
                        'approved_at' => $payload['approved_at'] ?? null,
                        'posted_at' => $payload['posted_at'] ?? null,
                    ]
                );
                break;

            case 'supplier_invoice_lines':
                SupplierInvoiceLine::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'supplier_invoice_id' => $payload['supplier_invoice_id'] ?? null,
                        'purchase_order_item_id' => $payload['purchase_order_item_id'] ?? null,
                        'grv_item_id' => $payload['grv_item_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'description' => $payload['description'] ?? '',
                        'quantity' => $payload['quantity'] ?? 1,
                        'unit_cost' => $payload['unit_cost'] ?? 0,
                        'discount_pct' => $payload['discount_pct'] ?? 0,
                        'tax_rate_id' => $payload['tax_rate_id'] ?? null,
                        'gl_account_id' => $payload['gl_account_id'] ?? null,
                        'line_total' => $payload['line_total'] ?? 0,
                    ]
                );
                break;

            case 'supplier_payment_allocations':
                if (! $trusted && ! ($actingUser?->hasRole(['business_owner', 'manager']) ?? false)) {
                    throw new \RuntimeException('supplier_payment_allocations: allocating a payment requires owner or manager access.');
                }

                SupplierPaymentAllocation::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'supplier_payment_id' => $payload['supplier_payment_id'] ?? null,
                        'supplier_invoice_id' => $payload['supplier_invoice_id'] ?? null,
                        'amount' => $payload['amount'] ?? 0,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'supplier_credit_notes':
                if (! $trusted && ! ($actingUser?->hasRole(['business_owner', 'manager']) ?? false)) {
                    throw new \RuntimeException('supplier_credit_notes: recording a credit/debit note requires owner or manager access.');
                }

                SupplierCreditNote::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'supplier_id' => $payload['supplier_id'] ?? null,
                        'supplier_invoice_id' => $payload['supplier_invoice_id'] ?? null,
                        'note_number' => $payload['note_number'] ?? '',
                        'note_type' => $payload['note_type'] ?? 'credit',
                        'note_date' => $payload['note_date'] ?? now()->toDateString(),
                        'reason' => $payload['reason'] ?? null,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'exchange_rate' => $payload['exchange_rate'] ?? 1,
                        'subtotal' => $payload['subtotal'] ?? 0,
                        'tax_total' => $payload['tax_total'] ?? 0,
                        'total' => $payload['total'] ?? 0,
                        'status' => $payload['status'] ?? 'draft',
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'supplier_credit_note_lines':
                SupplierCreditNoteLine::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'supplier_credit_note_id' => $payload['supplier_credit_note_id'] ?? null,
                        'supplier_invoice_line_id' => $payload['supplier_invoice_line_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'description' => $payload['description'] ?? '',
                        'quantity' => $payload['quantity'] ?? 1,
                        'unit_cost' => $payload['unit_cost'] ?? 0,
                        'gl_account_id' => $payload['gl_account_id'] ?? null,
                        'line_total' => $payload['line_total'] ?? 0,
                    ]
                );
                break;

                // AR module.
            case 'sales_orders':
                SalesOrder::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'order_number' => $payload['order_number'] ?? '',
                        'quotation_id' => $payload['quotation_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'sales_person_id' => $payload['sales_person_id'] ?? null,
                        'order_date' => $payload['order_date'] ?? now()->toDateString(),
                        'expected_delivery_date' => $payload['expected_delivery_date'] ?? null,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'exchange_rate' => $payload['exchange_rate'] ?? 1,
                        'subtotal' => $payload['subtotal'] ?? 0,
                        'discount_total' => $payload['discount_total'] ?? 0,
                        'tax_total' => $payload['tax_total'] ?? 0,
                        'total' => $payload['total'] ?? 0,
                        'deposit_required' => $payload['deposit_required'] ?? 0,
                        'deposit_paid' => $payload['deposit_paid'] ?? 0,
                        'status' => $payload['status'] ?? 'draft',
                        'delivery_status' => $payload['delivery_status'] ?? 'pending',
                        'invoicing_status' => $payload['invoicing_status'] ?? 'pending',
                        'terms_and_conditions' => $payload['terms_and_conditions'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'approved_by_user_id' => $payload['approved_by_user_id'] ?? null,
                        'approved_at' => $payload['approved_at'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'sales_order_items':
                SalesOrderItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'sales_order_id' => $payload['sales_order_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'description' => $payload['description'] ?? '',
                        'ordered_quantity' => $payload['ordered_quantity'] ?? 1,
                        'delivered_quantity' => $payload['delivered_quantity'] ?? 0,
                        'invoiced_quantity' => $payload['invoiced_quantity'] ?? 0,
                        'unit_price' => $payload['unit_price'] ?? 0,
                        'discount_pct' => $payload['discount_pct'] ?? 0,
                        'tax_rate_id' => $payload['tax_rate_id'] ?? null,
                        'tax_amount' => $payload['tax_amount'] ?? 0,
                        'line_total' => $payload['line_total'] ?? 0,
                        'notes' => $payload['notes'] ?? null,
                    ]
                );
                break;

            case 'delivery_notes':
                DeliveryNote::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'delivery_number' => $payload['delivery_number'] ?? '',
                        'sales_order_id' => $payload['sales_order_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'delivery_date' => $payload['delivery_date'] ?? now()->toDateString(),
                        'status' => $payload['status'] ?? 'pending',
                        'dispatched_by_user_id' => $payload['dispatched_by_user_id'] ?? null,
                        'received_by_name' => $payload['received_by_name'] ?? null,
                        'received_by_signature_path' => $payload['received_by_signature_path'] ?? null,
                        'received_at' => $payload['received_at'] ?? null,
                        'tracking_reference' => $payload['tracking_reference'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'delivery_note_items':
                DeliveryNoteItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'delivery_note_id' => $payload['delivery_note_id'] ?? null,
                        'sales_order_item_id' => $payload['sales_order_item_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'description' => $payload['description'] ?? '',
                        'dispatched_quantity' => $payload['dispatched_quantity'] ?? 0,
                        'accepted_quantity' => $payload['accepted_quantity'] ?? 0,
                        'rejected_quantity' => $payload['rejected_quantity'] ?? 0,
                        'rejection_reason' => $payload['rejection_reason'] ?? null,
                        'invoiced_quantity' => $payload['invoiced_quantity'] ?? 0,
                        'notes' => $payload['notes'] ?? null,
                    ]
                );
                break;

            case 'customer_receipts':
                CustomerReceipt::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'receipt_number' => $payload['receipt_number'] ?? '',
                        'customer_id' => $payload['customer_id'] ?? null,
                        'receipt_date' => $payload['receipt_date'] ?? now()->toDateString(),
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'exchange_rate' => $payload['exchange_rate'] ?? 1,
                        'amount' => $payload['amount'] ?? 0,
                        'base_amount' => $payload['base_amount'] ?? 0,
                        'unallocated_amount' => $payload['unallocated_amount'] ?? 0,
                        'payment_method' => $payload['payment_method'] ?? 'cash',
                        'bank_account_id' => $payload['bank_account_id'] ?? null,
                        'reference' => $payload['reference'] ?? null,
                        'is_advance' => $payload['is_advance'] ?? false,
                        'notes' => $payload['notes'] ?? null,
                        'journal_header_id' => $payload['journal_header_id'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                        'sync_status' => $payload['sync_status'] ?? 'synced',
                    ]
                );
                break;

            case 'customer_receipt_allocations':
                CustomerReceiptAllocation::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'customer_receipt_id' => $payload['customer_receipt_id'] ?? null,
                        'invoice_id' => $payload['invoice_id'] ?? null,
                        'allocated_amount' => $payload['allocated_amount'] ?? 0,
                        'tender_amount' => $payload['tender_amount'] ?? null,
                        'exchange_rate_used' => $payload['exchange_rate_used'] ?? 1,
                        'allocated_at' => $payload['allocated_at'] ?? now(),
                        'allocated_by_user_id' => $payload['allocated_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'customer_debit_notes':
                CustomerDebitNote::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'invoice_id' => $payload['invoice_id'] ?? null,
                        'debit_note_number' => $payload['debit_note_number'] ?? '',
                        'note_date' => $payload['note_date'] ?? now()->toDateString(),
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'exchange_rate' => $payload['exchange_rate'] ?? 1,
                        'subtotal' => $payload['subtotal'] ?? 0,
                        'tax_total' => $payload['tax_total'] ?? 0,
                        'total' => $payload['total'] ?? 0,
                        'reason' => $payload['reason'] ?? null,
                        'status' => $payload['status'] ?? 'draft',
                        'is_gl_posted' => $payload['is_gl_posted'] ?? false,
                        'journal_header_id' => $payload['journal_header_id'] ?? null,
                        'approved_by_user_id' => $payload['approved_by_user_id'] ?? null,
                        'approved_at' => $payload['approved_at'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'customer_debit_note_items':
                CustomerDebitNoteItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'customer_debit_note_id' => $payload['customer_debit_note_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'description' => $payload['description'] ?? '',
                        'quantity' => $payload['quantity'] ?? 1,
                        'unit_price' => $payload['unit_price'] ?? 0,
                        'tax_rate_id' => $payload['tax_rate_id'] ?? null,
                        'tax_amount' => $payload['tax_amount'] ?? 0,
                        'line_total' => $payload['line_total'] ?? 0,
                        'notes' => $payload['notes'] ?? null,
                    ]
                );
                break;

            case 'customer_adjustments':
                CustomerAdjustment::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'adjustment_number' => $payload['adjustment_number'] ?? '',
                        'adjustment_date' => $payload['adjustment_date'] ?? now()->toDateString(),
                        'adjustment_type' => $payload['adjustment_type'] ?? 'credit',
                        'amount' => $payload['amount'] ?? 0,
                        'reason_code' => $payload['reason_code'] ?? 'other',
                        'description' => $payload['description'] ?? '',
                        'reference' => $payload['reference'] ?? null,
                        'gl_offset_account_id' => $payload['gl_offset_account_id'] ?? null,
                        'status' => $payload['status'] ?? 'draft',
                        'is_gl_posted' => $payload['is_gl_posted'] ?? false,
                        'journal_header_id' => $payload['journal_header_id'] ?? null,
                        'approved_by_user_id' => $payload['approved_by_user_id'] ?? null,
                        'approved_at' => $payload['approved_at'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'customer_write_offs':
                CustomerWriteOff::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'write_off_number' => $payload['write_off_number'] ?? '',
                        'write_off_date' => $payload['write_off_date'] ?? now()->toDateString(),
                        'amount' => $payload['amount'] ?? 0,
                        'invoice_id' => $payload['invoice_id'] ?? null,
                        'reason' => $payload['reason'] ?? 'insolvency',
                        'status' => $payload['status'] ?? 'pending_approval',
                        'is_gl_posted' => $payload['is_gl_posted'] ?? false,
                        'journal_header_id' => $payload['journal_header_id'] ?? null,
                        'approved_by_user_id' => $payload['approved_by_user_id'] ?? null,
                        'approved_at' => $payload['approved_at'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'ar_collection_activities':
                ArCollectionActivity::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'activity_type' => $payload['activity_type'] ?? 'phone_call',
                        'activity_date' => $payload['activity_date'] ?? now(),
                        'contact_person' => $payload['contact_person'] ?? null,
                        'phone_or_email' => $payload['phone_or_email'] ?? null,
                        'notes' => $payload['notes'] ?? '',
                        'outcome' => $payload['outcome'] ?? null,
                        'next_action_date' => $payload['next_action_date'] ?? null,
                        'recorded_by_user_id' => $payload['recorded_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'ar_promises_to_pay':
                ArPromiseToPay::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'promise_date' => $payload['promise_date'] ?? now()->toDateString(),
                        'promised_payment_date' => $payload['promised_payment_date'] ?? now()->toDateString(),
                        'promised_amount' => $payload['promised_amount'] ?? 0,
                        'paid_amount' => $payload['paid_amount'] ?? 0,
                        'status' => $payload['status'] ?? 'pending',
                        'notes' => $payload['notes'] ?? null,
                        'collection_activity_id' => $payload['collection_activity_id'] ?? null,
                        'recorded_by_user_id' => $payload['recorded_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'ar_disputes':
                ArDispute::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'invoice_id' => $payload['invoice_id'] ?? null,
                        'dispute_number' => $payload['dispute_number'] ?? '',
                        'dispute_date' => $payload['dispute_date'] ?? now()->toDateString(),
                        'disputed_amount' => $payload['disputed_amount'] ?? 0,
                        'reason_category' => $payload['reason_category'] ?? 'pricing_error',
                        'description' => $payload['description'] ?? '',
                        'status' => $payload['status'] ?? 'open',
                        'resolution_notes' => $payload['resolution_notes'] ?? null,
                        'credit_note_id' => $payload['credit_note_id'] ?? null,
                        'assigned_to_user_id' => $payload['assigned_to_user_id'] ?? null,
                        'resolved_at' => $payload['resolved_at'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'customer_reconciliations':
                CustomerReconciliation::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'reconciliation_date' => $payload['reconciliation_date'] ?? now()->toDateString(),
                        'statement_cutoff_date' => $payload['statement_cutoff_date'] ?? now()->toDateString(),
                        'customer_statement_balance' => $payload['customer_statement_balance'] ?? 0,
                        'ledger_balance' => $payload['ledger_balance'] ?? 0,
                        'variance' => $payload['variance'] ?? 0,
                        'status' => $payload['status'] ?? 'in_progress',
                        'notes' => $payload['notes'] ?? null,
                        'reconciled_by_user_id' => $payload['reconciled_by_user_id'] ?? null,
                        'reconciled_at' => $payload['reconciled_at'] ?? null,
                    ]
                );
                break;

            case 'customer_reconciliation_items':
                CustomerReconciliationItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'customer_reconciliation_id' => $payload['customer_reconciliation_id'] ?? null,
                        'item_type' => $payload['item_type'] ?? 'missing_in_customer_statement',
                        'reference_number' => $payload['reference_number'] ?? null,
                        'item_date' => $payload['item_date'] ?? null,
                        'ledger_amount' => $payload['ledger_amount'] ?? 0,
                        'statement_amount' => $payload['statement_amount'] ?? 0,
                        'difference' => $payload['difference'] ?? 0,
                        'explanation' => $payload['explanation'] ?? null,
                        'is_resolved' => $payload['is_resolved'] ?? false,
                        'resolution_action' => $payload['resolution_action'] ?? null,
                    ]
                );
                break;

            case 'ap_tolerance_settings':
                // Deliberately unguarded — see role_permissions' identical
                // note: business_id IS this table's own primary key, so a
                // mismatched id can only ever create/update the caller's own
                // row (assertOwnership's businesses-table special case
                // already refuses anything else upstream of here).
                DB::table('ap_tolerance_settings')->updateOrInsert(
                    ['business_id' => $uuid],
                    [
                        'quantity_tolerance_pct' => $payload['quantity_tolerance_pct'] ?? 2.0,
                        'price_tolerance_pct' => $payload['price_tolerance_pct'] ?? 1.0,
                        'amount_tolerance' => $payload['amount_tolerance'] ?? 50.0,
                        'updated_at' => now(),
                    ]
                );
                break;

            case 'supplier_reconciliations':
                SupplierReconciliation::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'supplier_id' => $payload['supplier_id'] ?? null,
                        'statement_date' => $payload['statement_date'] ?? now()->toDateString(),
                        'statement_closing_balance' => $payload['statement_closing_balance'] ?? 0,
                        'smart_pos_closing_balance' => $payload['smart_pos_closing_balance'] ?? 0,
                        'variance' => $payload['variance'] ?? 0,
                        'status' => $payload['status'] ?? 'in_progress',
                        'notes' => $payload['notes'] ?? null,
                        'reconciled_by_user_id' => $payload['reconciled_by_user_id'] ?? null,
                        'reconciled_at' => $payload['reconciled_at'] ?? null,
                    ]
                );
                break;

            case 'supplier_reconciliation_items':
                SupplierReconciliationItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'reconciliation_id' => $payload['reconciliation_id'] ?? null,
                        'document_type' => $payload['document_type'] ?? 'invoice',
                        'document_reference' => $payload['document_reference'] ?? '',
                        'document_date' => $payload['document_date'] ?? now()->toDateString(),
                        'supplier_amount' => $payload['supplier_amount'] ?? 0,
                        'smart_pos_amount' => $payload['smart_pos_amount'] ?? 0,
                        'difference' => $payload['difference'] ?? 0,
                        'status' => $payload['status'] ?? 'matched',
                        'notes' => $payload['notes'] ?? null,
                    ]
                );
                break;

            case 'supplier_banks':
                SupplierBank::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'supplier_id' => $payload['supplier_id'] ?? null,
                        'bank_name' => $payload['bank_name'] ?? '',
                        'account_name' => $payload['account_name'] ?? null,
                        'account_number' => $payload['account_number'] ?? '',
                        'branch' => $payload['branch'] ?? null,
                        'branch_code' => $payload['branch_code'] ?? null,
                        'swift_code' => $payload['swift_code'] ?? null,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'is_default' => (bool) ($payload['is_default'] ?? false),
                        'is_active' => (bool) ($payload['is_active'] ?? true),
                    ]
                );
                break;

            case 'purchase_orders':
                $existingPo = PurchaseOrder::find($uuid);
                [$status, $justGated, $gateReason] = $this->gatePurchaseOrderStatus($existingPo, $payload);

                PurchaseOrder::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'receiving_location_id' => $payload['receiving_location_id'] ?? null,
                        'supplier_id' => $payload['supplier_id'] ?? null,
                        'supplier_name' => $payload['supplier_name'] ?? null,
                        'po_number' => $payload['po_number'] ?? '',
                        'status' => $status,
                        // total_ordered/total_received accepted from payload for initial insert.
                        // They will be overridden below if items exist (multi-device safe).
                        'total_ordered' => $payload['total_ordered'] ?? 0,
                        'total_received' => $payload['total_received'] ?? 0,
                        'notes' => $payload['notes'] ?? null,
                        'expected_date' => $payload['expected_date'] ?? null,
                        'additional_costs_json' => $payload['additional_costs_json'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
                $this->recomputePurchaseOrderTotals($uuid);

                if ($justGated) {
                    app(PurchaseOrderApprovalGate::class)->requestApproval($uuid, $gateReason);
                }
                break;

            case 'purchase_order_items':
                PurchaseOrderItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'purchase_order_id' => $payload['purchase_order_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'product_name' => $payload['product_name'] ?? '',
                        'ordered_qty' => $payload['ordered_qty'] ?? 0,
                        'received_qty' => $payload['received_qty'] ?? 0,
                        'unit_cost' => $payload['unit_cost'] ?? 0,
                        'received_unit_cost' => $payload['received_unit_cost'] ?? null,
                    ]
                );
                // Recompute the parent PO totals from all its items.
                if (! empty($payload['purchase_order_id'])) {
                    $this->recomputePurchaseOrderTotals($payload['purchase_order_id']);
                }
                break;

            case 'coupons':
                // Non-numeric fields: safe to overwrite.
                // uses_count: take the MAX of what's on the server vs what's incoming
                // so that concurrent redemptions from different devices both count.
                // This is monotonically increasing — a device can only push a higher count
                // when it has consumed a coupon; it never pushes a lower count.
                $existing = Coupon::find($uuid);
                Coupon::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'code' => $payload['code'] ?? '',
                        'description' => $payload['description'] ?? null,
                        'type' => $payload['type'] ?? 'percent',
                        'value' => $payload['value'] ?? 0,
                        'min_order_amount' => $payload['min_order_amount'] ?? 0,
                        'max_uses' => $payload['max_uses'] ?? null,
                        'uses_count' => max($payload['uses_count'] ?? 0, $existing?->uses_count ?? 0),
                        'is_active' => $payload['is_active'] ?? true,
                        'expires_at' => $payload['expires_at'] ?? null,
                    ]
                );
                break;

            case 'tills':
                $existingTill = Till::find($uuid);
                // A missing/omitted location_id must not be treated as "no
                // change requested" (that would fall through the mismatch
                // check below as a bare null) nor as "clear the location"
                // (an untrusted push could then null out an existing till's
                // location with zero authorization). Preserve the existing
                // value unless the payload explicitly names the field.
                $tillLocationId = array_key_exists('location_id', $payload)
                    ? $payload['location_id']
                    : $existingTill?->location_id;

                // A till's location can't move just because a device pushed a
                // different location_id — that would let any till silently
                // relocate itself. But it also shouldn't be portal-only: a
                // manager working the till app offline needs this to work like
                // every other Flutter-first action (act locally, sync later),
                // so an untrusted push is honored when the server's own copy
                // of the acting user actually holds manage_tills — the same
                // permission TillsController::reassignLocation requires — the
                // till has no open shift (mirrors that controller's guard;
                // moving a till mid-shift would orphan its cash reconciliation)
                // — and the acting user's own location scope covers both the
                // till's current and target location, the same restriction
                // TillsController::reassignLocation enforces via
                // currentLocationScope() for a branch-scoped manager.
                // Bare device pushes with no such user (or a lower-privileged
                // one) are still refused, same as before.
                if (! $trusted && $existingTill && $existingTill->location_id !== null
                    && $tillLocationId !== $existingTill->location_id) {
                    $tillBusinessId = $payload['business_id'] ?? $existingTill->business_id;
                    $actingRole = $actingUser?->getRoleNames()->first();
                    $authorizer = app(BackOfficeAuthorizer::class);
                    $actingScope = $actingUser !== null ? $authorizer->locationScope($actingUser) : null;

                    $authorized = $actingUser !== null
                        && $authorizer->can($tillBusinessId, $actingRole, BackOfficePermission::MANAGE_TILLS)
                        && ! Shift::where('till_id', $uuid)->where('status', 'open')->exists()
                        && ($actingScope === null || (
                            in_array($existingTill->location_id, $actingScope, true)
                            && in_array($tillLocationId, $actingScope, true)
                        ));

                    if (! $authorized) {
                        Log::warning('Ignored unauthorized attempt to move a till to a different location via sync push', [
                            'till_id' => $uuid,
                            'current_location_id' => $existingTill->location_id,
                            'attempted_location_id' => $tillLocationId,
                            'acting_user_id' => $actingUser?->id,
                        ]);
                        $tillLocationId = $existingTill->location_id;
                    } else {
                        TillLocationAudit::create([
                            'business_id' => $tillBusinessId,
                            'till_id' => $uuid,
                            'from_location_id' => $existingTill->location_id,
                            'to_location_id' => $tillLocationId,
                            'changed_by_user_id' => $actingUser->id,
                            'changed_by_user_name' => $actingUser->name,
                        ]);
                    }
                }

                Till::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $tillLocationId,
                        'device_id' => $payload['device_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'register_number' => $payload['register_number'] ?? 1,
                        'is_active' => $payload['is_active'] ?? true,
                    ]
                );
                break;

            case 'till_cash_movements':
                // Append-only ledger — see IMMUTABLE (delete is ignored).
                TillCashMovement::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'till_id' => $payload['till_id'] ?? null,
                        'shift_id' => $payload['shift_id'] ?? null,
                        'type' => $payload['type'] ?? 'cash_in',
                        'amount' => $payload['amount'] ?? 0,
                        'currency_code' => $payload['currency_code'] ?? null,
                        'reason' => $payload['reason'] ?? null,
                        'recorded_by_user_id' => $payload['recorded_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'shifts':
                // Same full-row-upsert footgun as 'businesses'/'invoices': a
                // status-only close push must not null out opening_float or
                // the identity fields of an already-open shift.
                $existingShift = Shift::find($uuid);
                $preserveShift = fn (string $key, $default = null) => array_key_exists($key, $payload)
                    ? $payload[$key]
                    : ($existingShift?->{$key} ?? $default);

                Shift::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $preserveShift('business_id'),
                        'location_id' => $preserveShift('location_id'),
                        'till_id' => $preserveShift('till_id'),
                        'cashier_id' => $preserveShift('cashier_id'),
                        'opened_at' => $preserveShift('opened_at', now()),
                        'closed_at' => $preserveShift('closed_at'),
                        'status' => $preserveShift('status', 'open'),
                        'opening_float' => $preserveShift('opening_float', 0),
                        // counted_cash/counted_cash_json are a physical cash
                        // count nobody else can verify — kept as reported.
                        'counted_cash' => $preserveShift('counted_cash'),
                        'counted_cash_json' => $preserveShift('counted_cash_json'),
                        'opening_float_json' => $preserveShift('opening_float_json'),
                        'notes' => $preserveShift('notes'),
                        // expected_cash/variance/total_sales/cash_sales/
                        // card_sales/mobile_money_sales/credit_sales/
                        // total_refunds/total_discounts/transaction_count
                        // are deliberately NOT written from the payload here
                        // — see recomputeShiftFigures() below, called
                        // unconditionally after every save. Till-skimming
                        // fraud: a self-reported total_sales/expected_cash
                        // with no independent derivation let a cashier
                        // under-report takings with the shortfall never
                        // showing as a variance.
                    ]
                );
                $this->recomputeShiftFigures($uuid);
                break;

            case 'expenses':
                // recorded_by_user_id is NOT NULL in the DB.
                // Prefer the payload value; if absent/empty, fall back to the existing row's
                // value so that cancellations and edits don't break on devices that pushed
                // the expense before this field was always populated.
                $recordedByUserId = ! empty($payload['recorded_by_user_id'])
                    ? $payload['recorded_by_user_id']
                    : Expense::find($uuid)?->recorded_by_user_id;

                if (empty($recordedByUserId) && ! empty($payload['business_id'])) {
                    // Fall back to the first user in the business to avoid data loss
                    $recordedByUserId = User::where('business_id', $payload['business_id'])->first()?->id;
                }

                if (empty($recordedByUserId)) {
                    // Last resort fallback to any user in the system (e.g. super admin)
                    $recordedByUserId = User::first()?->id;
                }

                if (empty($recordedByUserId)) {
                    // Genuinely new record with no user and no fallback possible — cannot insert.
                    throw new \RuntimeException(
                        'expenses: recorded_by_user_id is required but was not provided in the payload and no fallback user found.'
                    );
                }

                $expense = Expense::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'recorded_by_user_id' => $recordedByUserId,
                        'project_id' => $payload['project_id'] ?? null,
                        'category' => $payload['category'] ?? '',
                        'description' => $payload['description'] ?? null,
                        'amount' => $payload['amount'] ?? 0,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'base_equivalent' => $payload['base_equivalent'] ?? 0,
                        'exchange_rate' => $payload['exchange_rate'] ?? 1,
                        'payment_method' => $payload['payment_method'] ?? 'cash',
                        'mobile_provider' => $payload['mobile_provider'] ?? null,
                        'payment_reference' => $payload['payment_reference'] ?? null,
                        'bank_account_id' => $payload['bank_account_id'] ?? null,
                        'receipt_path' => $payload['receipt_path'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'expense_date' => $payload['expense_date'] ?? now(),
                        'deleted_at' => $payload['deleted_at'] ?? null,
                    ]
                );

                app(ExpensePostingService::class)->postIfReady($expense);
                break;

            case 'stock_takes':
                $currentStatus = StockTake::where('id', $uuid)->value('status');
                $incomingStatus = $payload['status'] ?? 'draft';

                if (! StockTake::isValidTransition($currentStatus, $incomingStatus)) {
                    throw new \RuntimeException(
                        "Invalid stock take transition: '{$currentStatus}' -> '{$incomingStatus}'"
                    );
                }

                // STC·08 — StockTakesController::approve() and the till's own
                // stock_take_report_screen.dart::_approve() both block while
                // any item needsRecount(), but neither of those is the only
                // door into this status column: an untrusted device pushing
                // straight to /api/v1/sync/push skips both and previously
                // hit no check at all here. Same category as the
                // requisition_issue gate above — a workflow gate that's only
                // enforced by callers, not by the sync entry point itself.
                if (! $trusted && $incomingStatus === 'approved') {
                    $stillNeedsRecount = StockTakeItem::where('stock_take_id', $uuid)
                        ->where('flagged_for_recount', true)
                        ->whereNull('recount_completed_at')
                        ->exists();

                    if ($stillNeedsRecount) {
                        throw new \RuntimeException('stock_takes: cannot approve while items still need a recount.');
                    }
                }

                StockTake::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'title' => $payload['title'] ?? '',
                        'status' => $incomingStatus,
                        'notes' => $payload['notes'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                        'approved_by_user_id' => $payload['approved_by_user_id'] ?? null,
                        'approved_at' => $payload['approved_at'] ?? null,
                        'review_comment' => $payload['review_comment'] ?? null,
                    ]
                );
                break;

            case 'stock_take_items':
                $existingItem = StockTakeItem::where('id', $uuid)->first();
                $incomingCounted = array_key_exists('counted_qty', $payload) && $payload['counted_qty'] !== null
                    ? (float) $payload['counted_qty']
                    : null;
                [$flagged, $recountCompletedAt] = $this->resolveStockTakeRecountState(
                    $existingItem,
                    $payload['stock_take_id'] ?? $existingItem?->stock_take_id,
                    (float) ($payload['system_qty'] ?? $existingItem?->system_qty ?? 0),
                    $incomingCounted,
                );

                $stockTakeItem = StockTakeItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'stock_take_id' => $payload['stock_take_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'product_name' => $payload['product_name'] ?? '',
                        'system_qty' => $payload['system_qty'] ?? 0,
                        'counted_qty' => $payload['counted_qty'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'flagged_for_recount' => $flagged,
                        'recount_completed_at' => $recountCompletedAt,
                    ]
                );

                // A device's own push payload never carries
                // flagged_for_recount/recount_completed_at (they're
                // server-computed above) — pull() only ever replays a
                // SyncRecord's ORIGINAL stored payload, never live model
                // state, so without a fresh authoritative record here no
                // device (including the one that just pushed this count)
                // would ever learn it was flagged. Same "echo the real
                // state back with device_id null" trick
                // PurchaseOrderApprovalGate uses for the same reason.
                if (! $trusted) {
                    SyncRecord::create([
                        'business_id' => $payload['business_id'] ?? null,
                        'table_name' => 'stock_take_items',
                        'record_uuid' => $uuid,
                        'operation' => 'upsert',
                        'payload' => [
                            'stock_take_id' => $stockTakeItem->stock_take_id,
                            'product_id' => $stockTakeItem->product_id,
                            'product_name' => $stockTakeItem->product_name,
                            'system_qty' => (float) $stockTakeItem->system_qty,
                            'counted_qty' => $stockTakeItem->counted_qty !== null ? (float) $stockTakeItem->counted_qty : null,
                            'notes' => $stockTakeItem->notes,
                            'flagged_for_recount' => $stockTakeItem->flagged_for_recount,
                            'recount_completed_at' => $stockTakeItem->recount_completed_at?->toIso8601String(),
                        ],
                        'source_updated_at' => now(),
                        'synced_at' => now(),
                    ]);
                }
                break;

            case 'role_permissions':
                // Same privilege-escalation class as the 'users' role gate
                // above, arguably worse: this doesn't just promote one
                // user, it redefines what an ENTIRE role can do — every
                // cashier at the business, not just one account. Had no
                // check at all: reproduced live, a real cashier's own
                // device granted the 'cashier' role manageUsers/
                // voidTransaction/issueRefund/editCurrencyRates/etc — once
                // synced to every till, every cashier account would have
                // gained all of it. BackOffice's own RolesController is
                // even stricter than the 'users' gate here — only the
                // business owner may manage roles at all (see
                // authorizeOwner() there) — mirrored exactly.
                if (! $trusted && ! ($actingUser?->hasRole('business_owner') ?? false)) {
                    throw new \RuntimeException('role_permissions: only the business owner can manage roles.');
                }

                RolePermission::updateOrCreate(
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'role' => $payload['role'] ?? '',
                    ],
                    [
                        'permissions_json' => $payload['permissions_json'] ?? '[]',
                    ]
                );
                break;

            case 'document_branding_settings':
                DocumentBrandingSetting::updateOrCreate(
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'document_type' => $payload['document_type'] ?? '',
                    ],
                    [
                        'use_letterhead' => $payload['use_letterhead'] ?? true,
                        'use_footer' => $payload['use_footer'] ?? true,
                        'show_logo' => $payload['show_logo'] ?? true,
                        'paper_size' => $payload['paper_size'] ?? null,
                    ]
                );
                break;

            case 'po_audit_logs':
                // Append-only — skip if a record with this uuid as po_id+action already exists
                PoAuditLog::firstOrCreate(
                    ['id' => (int) $uuid], // uuid may be stringified bigint
                    [
                        'po_id' => $payload['po_id'] ?? $uuid,
                        'user_id' => $payload['user_id'] ?? null,
                        'user_name' => $payload['user_name'] ?? '',
                        'action' => $payload['action'] ?? '',
                        'note' => $payload['note'] ?? null,
                        'snapshot_json' => $payload['snapshot_json'] ?? null,
                    ]
                );
                break;

            case 'employees':
                Employee::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'user_id' => $payload['user_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'job_title' => $payload['job_title'] ?? null,
                        'department' => $payload['department'] ?? null,
                        'phone' => $payload['phone'] ?? null,
                        'email' => $payload['email'] ?? null,
                        'national_id' => $payload['national_id'] ?? null,
                        'address' => $payload['address'] ?? null,
                        'emergency_contact_name' => $payload['emergency_contact_name'] ?? null,
                        'emergency_contact_phone' => $payload['emergency_contact_phone'] ?? null,
                        'pay_type' => $payload['pay_type'] ?? 'monthly',
                        'salary_amount' => $payload['salary_amount'] ?? 0,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'hire_date' => $payload['hire_date'] ?? null,
                        'termination_date' => $payload['termination_date'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'status' => $payload['status'] ?? 'active',
                        'photo_path' => $payload['photo_path'] ?? null,
                    ]
                );
                break;

            case 'salary_payments':
                // Same class of gap as 'users'/'role_permissions' above,
                // financial rather than permission-based: the Flutter UI
                // clearly means this to be restricted (employees_screen.dart/
                // employee_profile_screen.dart both gate the payroll button
                // behind Permission.processPayroll — owner/manager by
                // default), but nothing enforced that at the sync entry
                // point. Reproduced live: a plain cashier's device pushed a
                // fabricated $50,000 salary_payments record for a real
                // employee and it was accepted outright, with a real Dr
                // Wages / Cr Cash journal one push away from posting.
                if (! $trusted && ! ($actingUser?->hasRole(['business_owner', 'manager']) ?? false)) {
                    throw new \RuntimeException('salary_payments: recording a payment requires owner or manager access.');
                }

                $salaryPayment = SalaryPayment::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'employee_id' => $payload['employee_id'] ?? null,
                        'period' => $payload['period'] ?? '',
                        'amount' => $payload['amount'] ?? 0,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'base_equivalent' => $payload['base_equivalent'] ?? 0,
                        'exchange_rate' => $payload['exchange_rate'] ?? 1,
                        'payment_method' => $payload['payment_method'] ?? 'cash',
                        'reference' => $payload['reference'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'paid_by_user_id' => $payload['paid_by_user_id'] ?? null,
                        'paid_at' => $payload['paid_at'] ?? now(),
                        'bank_account_id' => $payload['bank_account_id'] ?? null,
                    ]
                );

                app(SalaryPostingService::class)->recordPayment($salaryPayment);
                break;

            case 'quotations':
                Quotation::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'quote_number' => $payload['quote_number'] ?? '',
                        'status' => $payload['status'] ?? 'draft',
                        'valid_until' => $payload['valid_until'] ?? null,
                        'subtotal' => $payload['subtotal'] ?? 0,
                        'discount_total' => $payload['discount_total'] ?? 0,
                        'tax_total' => $payload['tax_total'] ?? 0,
                        'total' => $payload['total'] ?? 0,
                        'notes' => $payload['notes'] ?? null,
                        'parent_quotation_id' => $payload['parent_quotation_id'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                        'sent_at' => $payload['sent_at'] ?? null,
                        'accepted_at' => $payload['accepted_at'] ?? null,
                        'rejected_at' => $payload['rejected_at'] ?? null,
                        'currency_code' => $payload['currency_code'] ?? null,
                        'exchange_rate' => $payload['exchange_rate'] ?? 1,
                    ]
                );
                break;

            case 'quotation_items':
                QuotationItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'quotation_id' => $payload['quotation_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'product_name' => $payload['product_name'] ?? '',
                        'quantity' => $payload['quantity'] ?? 0,
                        'unit_price' => $payload['unit_price'] ?? 0,
                        'discount_pct' => $payload['discount_pct'] ?? 0,
                        'tax_rate_id' => $payload['tax_rate_id'] ?? null,
                        'line_total' => $payload['line_total'] ?? 0,
                        'invoiced_quantity' => $payload['invoiced_quantity'] ?? 0,
                        // GLS·03
                        'sheet_lot_id' => $payload['sheet_lot_id'] ?? null,
                        'sheet_cut_width' => $payload['sheet_cut_width'] ?? null,
                        'sheet_cut_height' => $payload['sheet_cut_height'] ?? null,
                    ]
                );
                break;

            case 'invoices':
                // Same full-row-upsert footgun as 'businesses' above: an
                // incomplete payload (e.g. a status-only or notes-only
                // resend) previously reset every omitted field back to its
                // bare default, silently wiping subtotal/discount_total/
                // tax_total/total on a genuine invoice. Preserve whatever
                // the payload omits instead of defaulting it.
                $existingInvoice = Invoice::find($uuid);
                $preserveInvoice = fn (string $key, $default = null) => array_key_exists($key, $payload)
                    ? $payload[$key]
                    : ($existingInvoice?->{$key} ?? $default);

                Invoice::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $preserveInvoice('business_id'),
                        'location_id' => $preserveInvoice('location_id'),
                        'customer_id' => $preserveInvoice('customer_id'),
                        'quotation_id' => $preserveInvoice('quotation_id'),
                        'invoice_number' => $preserveInvoice('invoice_number', ''),
                        'type' => $preserveInvoice('type', 'standard'),
                        'status' => $preserveInvoice('status', 'draft'),
                        'issue_date' => $preserveInvoice('issue_date', now()),
                        'due_date' => $preserveInvoice('due_date'),
                        'payment_terms_days' => $preserveInvoice('payment_terms_days', 0),
                        'subtotal' => $preserveInvoice('subtotal', 0),
                        'discount_total' => $preserveInvoice('discount_total', 0),
                        'tax_total' => $preserveInvoice('tax_total', 0),
                        'deposit_required' => $preserveInvoice('deposit_required', 0),
                        'total' => $preserveInvoice('total', 0),
                        // amount_paid is intentionally NOT preserved from the
                        // payload — it's re-derived below from the real
                        // invoice_payments ledger regardless of what any
                        // client claims (the AR-fraud fix).
                        'amount_paid' => $existingInvoice?->amount_paid ?? 0,
                        'recurring_schedule_id' => $preserveInvoice('recurring_schedule_id'),
                        'notes' => $preserveInvoice('notes'),
                        'created_by_user_id' => $preserveInvoice('created_by_user_id'),
                    ]
                );
                $this->recomputeInvoiceAmountPaid($uuid);
                break;

            case 'invoice_items':
                InvoiceItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'invoice_id' => $payload['invoice_id'] ?? null,
                        'quotation_item_id' => $payload['quotation_item_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'product_name' => $payload['product_name'] ?? '',
                        'quantity' => $payload['quantity'] ?? 0,
                        'unit_price' => $payload['unit_price'] ?? 0,
                        'discount_pct' => $payload['discount_pct'] ?? 0,
                        'tax_rate_id' => $payload['tax_rate_id'] ?? null,
                        'line_total' => $payload['line_total'] ?? 0,
                    ]
                );
                break;

            case 'invoice_payments':
                // Append-only ledger — see IMMUTABLE (delete is ignored).
                $invoicePayment = InvoicePayment::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'invoice_id' => $payload['invoice_id'] ?? null,
                        'method' => $payload['method'] ?? 'cash',
                        'amount' => $payload['amount'] ?? 0,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'exchange_rate_used' => $payload['exchange_rate_used'] ?? 1,
                        'base_equivalent' => $payload['base_equivalent'] ?? 0,
                        'recorded_by_user_id' => $payload['recorded_by_user_id'] ?? null,
                        'paid_at' => $payload['paid_at'] ?? now(),
                        'bank_account_id' => $payload['bank_account_id'] ?? null,
                    ]
                );
                if (! empty($payload['invoice_id'])) {
                    $this->recomputeInvoiceAmountPaid($payload['invoice_id']);
                }
                // The missing half of invoice accounting — see
                // InvoicePaymentPostingService's doc comment.
                app(InvoicePaymentPostingService::class)->postIfReady($invoicePayment);
                break;

            case 'credit_notes':
                // Unlike a POS refund (approval_requests-gated) or an
                // invoice payment write-off, a credit note had zero
                // authorization check of any kind — any device could credit
                // money back against a customer's balance. Gated behind
                // finance.credit_note.create, the permission already in the
                // catalogue (granted to accountant/finance_manager by
                // default) but never wired to an enforcement point. Only
                // gates the initial creation — a later resend of the same
                // uuid (e.g. re-syncing after a connectivity drop) is not
                // re-litigated.
                if (! $trusted && CreditNote::find($uuid) === null) {
                    $creditNoteBusinessId = $payload['business_id'] ?? null;
                    $actingRole = $actingUser?->getRoleNames()->first();

                    if (! app(BackOfficeAuthorizer::class)->can($creditNoteBusinessId, $actingRole, BackOfficePermission::FINANCE_CREDIT_NOTE_CREATE)) {
                        throw new \RuntimeException('credit_notes: creating a credit note requires the finance.credit_note.create permission.');
                    }
                }

                CreditNote::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'invoice_id' => $payload['invoice_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'credit_note_number' => $payload['credit_note_number'] ?? '',
                        'reason' => $payload['reason'] ?? null,
                        'subtotal' => $payload['subtotal'] ?? 0,
                        'tax_total' => $payload['tax_total'] ?? 0,
                        'total' => $payload['total'] ?? 0,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'credit_note_items':
                // Append-only — see IMMUTABLE (delete is ignored).
                CreditNoteItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'credit_note_id' => $payload['credit_note_id'] ?? null,
                        'invoice_item_id' => $payload['invoice_item_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'product_name' => $payload['product_name'] ?? '',
                        'quantity' => $payload['quantity'] ?? 0,
                        'unit_price' => $payload['unit_price'] ?? 0,
                        'line_total' => $payload['line_total'] ?? 0,
                    ]
                );
                break;

            case 'recurring_invoice_schedules':
                RecurringInvoiceSchedule::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'template_json' => $payload['template_json'] ?? [],
                        'frequency' => $payload['frequency'] ?? 'monthly',
                        'next_run_date' => $payload['next_run_date'] ?? now(),
                        'is_active' => $payload['is_active'] ?? true,
                        'last_generated_invoice_id' => $payload['last_generated_invoice_id'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
                break;

                // Client-posted journals — a plain mirror. The client (Flutter,
                // once cut over via client_gl_posting_enabled_at) has already
                // computed and "posted" this journal locally; Laravel never
                // recomputes or re-validates it here, only stores what arrives.
                // See JournalService/SalePostingService for the server-side
                // equivalent used before a business is cut over.
            case 'journal_headers':
                $businessId = $payload['business_id'] ?? null;
                $sourceType = $payload['source_type'] ?? null;
                $sourceId = $payload['source_id'] ?? null;

                // Defense-in-depth against exactly the class of bypass the
                // salary_payments/supplier_payments/assets escalation guards
                // above were fixed for: those gate the higher-level table
                // (salary_payments, etc.), but a modified client could skip
                // straight to journal_headers/journal_lines/general_ledger
                // instead and post the same fraudulent entry directly,
                // sidestepping every one of those guards. Deliberately an
                // allowlist of the *known*-sensitive source types those
                // fixes already cover, not a blanket permission check on
                // this table — journal_headers is the shared plumbing every
                // legitimate posting flows through, including a plain
                // cashier's own sale (SalePostingService), so restricting it
                // broadly would break that core, high-frequency path.
                // 'depreciation' is deliberately excluded: it's a system
                // sweep tied to no particular user's action (see
                // DepreciationPostingService), not a role-gated one.
                $ownerOrManagerJournalSources = ['salary_payment', 'supplier_payment', 'cash_vault_drop', 'cash_vault_deposit', 'cash_vault_count'];
                $ownerOnlyJournalSources = ['asset_acquisition', 'asset_disposal'];

                if (! $trusted && in_array($sourceType, $ownerOrManagerJournalSources, true)
                    && ! ($actingUser?->hasRole(['business_owner', 'manager']) ?? false)) {
                    throw new \RuntimeException("journal_headers: posting a {$sourceType} journal requires owner or manager access.");
                }

                if (! $trusted && in_array($sourceType, $ownerOnlyJournalSources, true)
                    && ! ($actingUser?->hasRole('business_owner') ?? false)) {
                    throw new \RuntimeException("journal_headers: posting a {$sourceType} journal requires the business owner role.");
                }

                // Soft guard against the exact double-post this feature's
                // cutover flag is designed to prevent — a second header for
                // a source that already has one is almost certainly a race
                // or a bug, not a legitimate second journal. Logged, not
                // rejected: a false positive here must never turn into a
                // permanently stuck sync record for a device.
                if ($businessId && $sourceType && $sourceId) {
                    $duplicate = JournalHeader::where('business_id', $businessId)
                        ->where('source_type', $sourceType)
                        ->where('source_id', $sourceId)
                        ->where('id', '!=', $uuid)
                        ->exists();

                    if ($duplicate) {
                        Log::warning("Accounting: client pushed journal_header {$uuid} for {$sourceType}:{$sourceId}, but another journal already exists for that source — possible double-post, needs manual review.");
                    }
                }

                JournalHeader::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $businessId,
                        'journal_number' => $payload['journal_number'] ?? null,
                        'trans_date' => $payload['trans_date'] ?? now()->toDateString(),
                        'description' => $payload['description'] ?? null,
                        'source_type' => $sourceType,
                        'source_id' => $sourceId,
                        'status' => $payload['status'] ?? 'posted',
                        'posted_at' => $payload['posted_at'] ?? null,
                        'posted_by_user_id' => $payload['posted_by_user_id'] ?? null,
                        'reversed_by_journal_id' => $payload['reversed_by_journal_id'] ?? null,
                        'reversed_at' => $payload['reversed_at'] ?? null,
                        'reversed_by_user_id' => $payload['reversed_by_user_id'] ?? null,
                        'reversal_of_journal_id' => $payload['reversal_of_journal_id'] ?? null,
                    ]
                );
                break;

            case 'journal_lines':
                $this->assertAccountOwnedByJournalBusiness($payload['journal_header_id'] ?? null, $payload['gl_account_id'] ?? null, 'journal_lines');

                JournalLine::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'journal_header_id' => $payload['journal_header_id'] ?? null,
                        'gl_account_id' => $payload['gl_account_id'] ?? null,
                        'debit' => $payload['debit'] ?? 0,
                        'credit' => $payload['credit'] ?? 0,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'exchange_rate' => $payload['exchange_rate'] ?? 1,
                        'foreign_debit' => $payload['foreign_debit'] ?? 0,
                        'foreign_credit' => $payload['foreign_credit'] ?? 0,
                        'party_type' => $payload['party_type'] ?? null,
                        'party_id' => $payload['party_id'] ?? null,
                        'description' => $payload['description'] ?? null,
                    ]
                );
                break;

            case 'general_ledger':
                $glBusinessId = $payload['business_id'] ?? null;
                $glAccountId = $payload['gl_account_id'] ?? null;

                if ($glBusinessId && $glAccountId) {
                    $accountOwner = GlAccount::where('id', $glAccountId)->value('business_id');
                    if ($accountOwner !== null && (string) $accountOwner !== (string) $glBusinessId) {
                        throw new \RuntimeException('general_ledger: referenced gl_account does not belong to this business.');
                    }
                }

                GeneralLedgerEntry::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $glBusinessId,
                        'trans_date' => $payload['trans_date'] ?? now()->toDateString(),
                        'journal_header_id' => $payload['journal_header_id'] ?? null,
                        'gl_account_id' => $glAccountId,
                        'debit' => $payload['debit'] ?? 0,
                        'credit' => $payload['credit'] ?? 0,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'exchange_rate' => $payload['exchange_rate'] ?? 1,
                        'foreign_debit' => $payload['foreign_debit'] ?? 0,
                        'foreign_credit' => $payload['foreign_credit'] ?? 0,
                        'party_type' => $payload['party_type'] ?? null,
                        'party_id' => $payload['party_id'] ?? null,
                        'description' => $payload['description'] ?? null,
                        'status' => $payload['status'] ?? 'active',
                        'reconciled_at' => $payload['reconciled_at'] ?? null,
                        'bank_reconciliation_id' => $payload['bank_reconciliation_id'] ?? null,
                    ]
                );

                // general_ledger rows are the last thing the client writes
                // when posting a journal, so by the time one arrives the
                // header and all of its lines should already exist —
                // the natural point to re-check integrity.
                if ($payload['journal_header_id'] ?? null) {
                    $this->checkClientJournalIntegrity(JournalHeader::find($payload['journal_header_id']));
                }
                break;

            case 'account_categories':
                // Previously had no case at all — this table was Laravel-only
                // (ChartOfAccountsSeeder), so a device push for it silently
                // fell through the switch and vanished (accepted, never
                // written). Now that Flutter's ChartOfAccountsService can
                // create a brand-new top-level category offline (see
                // chart_of_accounts_service.dart), it needs a real case, with
                // the same owner-or-manager gate as its sibling tables below.
                $existingCategory = AccountCategory::where('id', $uuid)->first();
                $incomingCategory = [
                    'business_id' => $payload['business_id'] ?? null,
                    'name' => $payload['name'] ?? '',
                    'code' => $payload['code'] ?? null,
                    'is_debit_normal' => $payload['is_debit_normal'] ?? true,
                    'statement_type' => $payload['statement_type'] ?? 'balance_sheet',
                    'reporting_order' => $payload['reporting_order'] ?? 99,
                    'is_system' => $payload['is_system'] ?? false,
                ];

                if (! $trusted && $this->chartOfAccountsRowChanged($existingCategory, $incomingCategory)) {
                    $this->requireOwnerOrManager($actingUser, 'account_categories: creating or changing a chart-of-accounts category');
                }

                AccountCategory::updateOrCreate(['id' => $uuid], $incomingCategory);
                break;

            case 'account_sub_categories':
                // Was idempotent-by-id with NO authority check at all — a
                // client only ever pushes one of these to bootstrap its own
                // "Bank Accounts" subcategory the first time a bank account
                // is created on that device (matches
                // ChartOfAccountsSeeder::ensureSubCategory()'s own shape),
                // but the generic device sync path had nothing stopping any
                // authenticated till from pushing an arbitrary new
                // subcategory, or silently rewriting an existing one's name/
                // parent category by reusing its known id. Same fraud class
                // as 'account_role_mappings'/'bank_accounts' just below —
                // gated the same way: owner or manager required (matches
                // Permission.manageCashVault, the role floor already guarding
                // the one legitimate caller, bank account creation) whenever
                // an untrusted push creates a new row or changes an
                // existing one. A device resending its own already-current
                // row unchanged is still let through.
                $existingSubCategory = AccountSubCategory::where('id', $uuid)->first();
                $incomingSubCategory = [
                    'business_id' => $payload['business_id'] ?? null,
                    'account_category_id' => $payload['account_category_id'] ?? null,
                    'name' => $payload['name'] ?? '',
                    'reporting_order' => $payload['reporting_order'] ?? 99,
                ];

                if (! $trusted && $this->chartOfAccountsRowChanged($existingSubCategory, $incomingSubCategory)) {
                    $this->requireOwnerOrManager($actingUser, 'account_sub_categories: creating or changing a chart-of-accounts subcategory');
                }

                AccountSubCategory::updateOrCreate(['id' => $uuid], $incomingSubCategory);
                break;

            case 'gl_accounts':
                // Same gap and same fix as 'account_sub_categories' above —
                // this ordinarily server-only table (chart of accounts is
                // seeded once, see ChartOfAccountsSeeder) accepted an
                // untrusted push with no authority check whatsoever. A
                // reused id here isn't just a routing lever like
                // account_role_mappings' single gl_account_id field — every
                // one of code/category/control_type/status defines what an
                // account *is* for reporting purposes, so any change to an
                // existing row (not just row creation) needs the same gate.
                $existingGlAccount = GlAccount::where('id', $uuid)->first();
                $incomingGlAccount = [
                    'business_id' => $payload['business_id'] ?? null,
                    'code' => $payload['code'] ?? '',
                    'name' => $payload['name'] ?? '',
                    'account_category_id' => $payload['account_category_id'] ?? null,
                    'account_sub_category_id' => $payload['account_sub_category_id'] ?? null,
                    'allow_direct_posting' => $payload['allow_direct_posting'] ?? true,
                    'control_type' => $payload['control_type'] ?? null,
                    'must_be_positive' => $payload['must_be_positive'] ?? false,
                    'status' => $payload['status'] ?? 'active',
                ];

                if (! $trusted && $this->chartOfAccountsRowChanged($existingGlAccount, $incomingGlAccount)) {
                    $this->requireOwnerOrManager($actingUser, 'gl_accounts: creating or changing a chart-of-accounts entry');
                }

                GlAccount::updateOrCreate(['id' => $uuid], $incomingGlAccount);
                break;

            case 'bank_accounts':
                // Same fraud-guard shape as account_role_mappings just below
                // (and gated on the same field for the same reason: this is
                // the lever that decides which real GL account a bank
                // account's activity posts to). The till only shows the
                // bank accounts screen to Permission.manageCashVault
                // (owner/manager by default) — a client-side gate a raw API
                // call bypasses entirely. Scoped to gl_account_id changes on
                // an *existing* account, mirroring account_role_mappings —
                // creating a brand-new account or editing its name/branch/
                // currency isn't itself a money-redirect vector.
                $existingBankAccountGlId = BankAccount::where('id', $uuid)->value('gl_account_id');
                $incomingBankAccountGlId = $payload['gl_account_id'] ?? null;

                if (! $trusted && $existingBankAccountGlId !== null && $incomingBankAccountGlId !== $existingBankAccountGlId) {
                    $actingRole = $actingUser?->getRoleNames()->first();

                    if (! in_array($actingRole, ['business_owner', 'manager'], true)) {
                        throw new \RuntimeException('bank_accounts: changing which GL account a bank account posts to requires the owner or manager role.');
                    }
                }

                BankAccount::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'account_number' => $payload['account_number'] ?? null,
                        'branch' => $payload['branch'] ?? null,
                        'branch_code' => $payload['branch_code'] ?? null,
                        'swift_code' => $payload['swift_code'] ?? null,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'gl_account_id' => $incomingBankAccountGlId,
                        'is_active' => $payload['is_active'] ?? true,
                        'accepts_card_swipe' => $payload['accepts_card_swipe'] ?? true,
                        'show_on_documents' => $payload['show_on_documents'] ?? true,
                    ]
                );
                break;

            case 'bank_reconciliations':
                $currentReconciliationStatus = BankReconciliation::where('id', $uuid)->value('status');
                $incomingReconciliationStatus = $payload['status'] ?? 'in_progress';

                if (! BankReconciliation::isValidTransition($currentReconciliationStatus, $incomingReconciliationStatus)) {
                    throw new \RuntimeException(
                        "Invalid bank reconciliation transition: '{$currentReconciliationStatus}' -> '{$incomingReconciliationStatus}'"
                    );
                }

                BankReconciliation::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'bank_account_id' => $payload['bank_account_id'] ?? null,
                        'statement_date' => $payload['statement_date'] ?? now()->toDateString(),
                        'statement_balance' => $payload['statement_balance'] ?? 0,
                        'status' => $incomingReconciliationStatus,
                        'started_by_user_id' => $payload['started_by_user_id'] ?? null,
                        'started_at' => $payload['started_at'] ?? now(),
                        'completed_by_user_id' => $payload['completed_by_user_id'] ?? null,
                        'completed_at' => $payload['completed_at'] ?? null,
                    ]
                );
                break;

            case 'account_role_mappings':
                // Critical fraud guard, same shape as the 'users' role-
                // escalation fix above: this row decides which real GL
                // account every future sale/payment/posting for an entire
                // category lands in (see AccountRoleMappingService's doc
                // comment). The till only ever shows its edit screen to
                // UserRole.owner (settings_screen.dart) — but that's a
                // client-side gate a modified app or a raw API call bypasses
                // entirely, and this generic device sync path had no
                // server-side check at all. Without this, any authenticated
                // device could silently redirect where a whole revenue/
                // expense category posts — a live internal-fraud vector, not
                // just a cosmetic bug. A device resending its own already-
                // current mapping unchanged is still let through so routine
                // syncs never spuriously fail.
                $mappingBusinessId = $payload['business_id'] ?? null;
                $mappingRole = $payload['role'] ?? null;
                $currentMappingGlAccountId = AccountRoleMapping::where('business_id', $mappingBusinessId)
                    ->where('role', $mappingRole)
                    ->value('gl_account_id');
                $incomingMappingGlAccountId = $payload['gl_account_id'] ?? null;

                if (! $trusted && $incomingMappingGlAccountId !== $currentMappingGlAccountId) {
                    $actingRole = $actingUser?->getRoleNames()->first();

                    if ($actingRole !== 'business_owner') {
                        throw new \RuntimeException('account_role_mappings: changing GL account routing requires the business owner role.');
                    }
                }

                AccountRoleMapping::updateOrCreate(
                    [
                        'business_id' => $mappingBusinessId,
                        'role' => $mappingRole,
                    ],
                    [
                        'id' => $uuid,
                        'gl_account_id' => $incomingMappingGlAccountId,
                    ]
                );
                break;

            case 'supplier_payments':
                // Append-only ledger — see IMMUTABLE (delete is ignored).
                // The Flutter-first counterpart of 'invoice_payments' above:
                // the device already posted its own GL journal locally (or
                // will once cut over), this just mirrors the row and lets
                // the server catch it if the client hasn't posted yet.
                //
                // Same class of gap 'salary_payments' above was already
                // fixed for (reproduced live there as a fabricated $50,000
                // payroll payment from a plain cashier device) — this
                // sibling table had no equivalent guard. A fabricated
                // supplier_payments row is the accounts-payable version of
                // the same fraud: it reduces what the business shows as
                // owing to a real supplier, with a real Dr Accounts Payable
                // / Cr Cash-or-Bank journal one push away from posting.
                if (! $trusted && ! ($actingUser?->hasRole(['business_owner', 'manager']) ?? false)) {
                    throw new \RuntimeException('supplier_payments: recording a payment requires owner or manager access.');
                }

                $supplierPayment = SupplierPayment::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'supplier_id' => $payload['supplier_id'] ?? null,
                        'amount' => $payload['amount'] ?? 0,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'exchange_rate_used' => $payload['exchange_rate_used'] ?? 1,
                        'base_equivalent' => $payload['base_equivalent'] ?? $payload['amount'] ?? 0,
                        'payment_date' => $payload['payment_date'] ?? now()->toDateString(),
                        'method' => $payload['method'] ?? 'cash',
                        'reference' => $payload['reference'] ?? null,
                        'recorded_by_user_id' => $payload['recorded_by_user_id'] ?? null,
                        'bank_account_id' => $payload['bank_account_id'] ?? null,
                    ]
                );
                app(SupplierPaymentService::class)->postIfReady($supplierPayment);
                break;

            case 'assets':
                // Append-only-ish — see IMMUTABLE; disposal flips `status`
                // via the same full-row upsert, never a delete — guarded by
                // Asset::isValidTransition() below so a device replaying its
                // own stale 'active' snapshot can't resurrect a disposed
                // asset (see that method's doc comment).
                //
                // Same fraud class as 'salary_payments'/'supplier_payments'
                // above, but the dangerous action here is *creation* as much
                // as disposal: a fabricated acquisition posts a real Dr
                // Fixed Assets / Cr Cash-or-Bank entry for an item that was
                // never actually bought, and a fabricated disposal does the
                // same for proceeds that were never actually received. The
                // till only shows the Assets screen at all to UserRole.owner
                // (more_screen.dart) — mirrored here for every untrusted
                // write to this table, not just the status field, since
                // acquisition_cost/disposal_proceeds are exactly as
                // dangerous as status itself.
                if (! $trusted && ! ($actingUser?->hasRole('business_owner') ?? false)) {
                    throw new \RuntimeException('assets: creating or editing an asset requires the business owner role.');
                }

                $currentAssetStatus = Asset::where('id', $uuid)->value('status');
                $incomingAssetStatus = $payload['status'] ?? 'active';

                if (! Asset::isValidTransition($currentAssetStatus, $incomingAssetStatus)) {
                    throw new \RuntimeException(
                        "Invalid asset status transition: '{$currentAssetStatus}' -> '{$incomingAssetStatus}'"
                    );
                }

                $asset = Asset::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'asset_number' => $payload['asset_number'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'category' => $payload['category'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'acquisition_date' => $payload['acquisition_date'] ?? now()->toDateString(),
                        'acquisition_cost' => $payload['acquisition_cost'] ?? 0,
                        'salvage_value' => $payload['salvage_value'] ?? 0,
                        'useful_life_months' => $payload['useful_life_months'] ?? 0,
                        'funding_method' => $payload['funding_method'] ?? 'cash',
                        'bank_account_id' => $payload['bank_account_id'] ?? null,
                        'disposal_bank_account_id' => $payload['disposal_bank_account_id'] ?? null,
                        'status' => $incomingAssetStatus,
                        'disposed_at' => $payload['disposed_at'] ?? null,
                        'disposal_proceeds' => $payload['disposal_proceeds'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
                app(AssetPostingService::class)->postIfReady($asset);
                break;

            case 'approval_rule_sets':
                // Same fraud class as 'role_permissions'/'account_role_mappings'
                // above: a rule set's `is_enabled` flag and (via its rules)
                // required_role/min_approvers/condition thresholds ARE the
                // approval control for an entire process (e.g. every PO over
                // some amount) — loosening them from an untrusted device is
                // equivalent to forging server-side sign-off. The till only
                // shows rule-set configuration to UserRole.owner, mirrored here.
                if (! $trusted && ! ($actingUser?->hasRole('business_owner') ?? false)) {
                    throw new \RuntimeException('approval_rule_sets: only the business owner can manage approval rule sets.');
                }

                ApprovalRuleSet::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'process' => $payload['process'] ?? '',
                        'name' => $payload['name'] ?? '',
                        'description' => $payload['description'] ?? null,
                        'is_enabled' => $payload['is_enabled'] ?? true,
                    ]
                );
                break;

            case 'approval_rules':
                // Same guard as 'approval_rule_sets' just above — this is the
                // row that actually carries required_role/min_approvers/
                // escalate_to_role for one level of a rule set.
                if (! $trusted && ! ($actingUser?->hasRole('business_owner') ?? false)) {
                    throw new \RuntimeException('approval_rules: only the business owner can manage approval rules.');
                }

                ApprovalRule::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'rule_set_id' => $payload['rule_set_id'] ?? null,
                        'level' => $payload['level'] ?? 1,
                        'condition_type' => $payload['condition_type'] ?? null,
                        'condition_value' => $payload['condition_value'] ?? null,
                        'condition_value_max' => $payload['condition_value_max'] ?? null,
                        'required_role' => $payload['required_role'] ?? null,
                        'approval_group_id' => $payload['approval_group_id'] ?? null,
                        'min_approvers' => $payload['min_approvers'] ?? 1,
                        'is_sequential' => $payload['is_sequential'] ?? true,
                        'sla_hours' => $payload['sla_hours'] ?? 24,
                        'escalate_to_role' => $payload['escalate_to_role'] ?? null,
                        'escalate_after_hours' => $payload['escalate_after_hours'] ?? null,
                        'require_different_user' => $payload['require_different_user'] ?? true,
                    ]
                );
                break;

            case 'approval_delegations':
                // Different fraud shape from the two cases above: this row
                // doesn't change what a rule requires, it hands the
                // delegate_user_id the delegator's approval authority
                // outright (see ApprovalRuleEngine::canApprove()'s
                // delegatorRoles fallback, both sides). An untrusted device
                // naming ANY delegator_user_id (e.g. the owner) and itself
                // as delegate would self-grant that authority — worse than
                // the rule-set gate above since it bypasses required_role
                // checks entirely rather than just weakening them. Allow it
                // only when the acting user IS the named delegator (genuine
                // self-service delegation, e.g. "I'm on leave") or is the
                // business owner (emergency override on someone else's
                // behalf) — mirrors no existing till screen yet (delegation
                // UI is still pending), so this is deliberately conservative.
                $delegatorUserId = $payload['delegator_user_id'] ?? null;
                if (! $trusted
                    && $actingUser?->id !== $delegatorUserId
                    && ! ($actingUser?->hasRole('business_owner') ?? false)) {
                    throw new \RuntimeException('approval_delegations: you may only create a delegation from your own account, or as the business owner.');
                }

                ApprovalDelegation::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'delegator_user_id' => $delegatorUserId,
                        'delegate_user_id' => $payload['delegate_user_id'] ?? null,
                        // Scopes this delegation to one process/stage — null
                        // on either means "every process"/"every level" for
                        // this delegator, kept for the handful of pre-v76
                        // rows created before the self-service screen existed.
                        'process' => $payload['process'] ?? null,
                        'level' => $payload['level'] ?? null,
                        'reason' => $payload['reason'] ?? null,
                        'starts_at' => $payload['starts_at'] ?? null,
                        'ends_at' => $payload['ends_at'] ?? null,
                        'is_active' => $payload['is_active'] ?? true,
                    ]
                );
                break;

            case 'approval_groups':
                // Same fraud class as 'approval_rule_sets' above — a group's
                // membership (via approval_group_members below) IS who can
                // clear a stage, so creating/renaming a group is owner-only.
                if (! $trusted && ! ($actingUser?->hasRole('business_owner') ?? false)) {
                    throw new \RuntimeException('approval_groups: only the business owner can manage approver groups.');
                }

                ApprovalGroup::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'description' => $payload['description'] ?? null,
                    ]
                );
                break;

            case 'approval_group_members':
                // Adding/removing a named member IS granting/revoking that
                // person's authority to clear whatever stages the group is
                // assigned to — owner-only, same as the group itself.
                if (! $trusted && ! ($actingUser?->hasRole('business_owner') ?? false)) {
                    throw new \RuntimeException('approval_group_members: only the business owner can manage approver group membership.');
                }

                ApprovalGroupMember::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'group_id' => $payload['group_id'] ?? null,
                        'user_id' => $payload['user_id'] ?? null,
                    ]
                );
                break;

            case 'approval_request_stage_decisions':
                // Append-only audit trail — never mutated once written, and
                // the device pushing it must be reporting its own action
                // (acted_by_user_id), never fabricating a decision on behalf
                // of someone else. The authority check for the decision
                // itself already happened in the 'approval_requests' case
                // above (both are pushed in the same sync batch); this case
                // only guards against forging *who* made it.
                $actedByUserId = $payload['acted_by_user_id'] ?? null;
                if (! $trusted && $actingUser?->id !== $actedByUserId) {
                    throw new \RuntimeException('approval_request_stage_decisions: you may only record a decision as yourself.');
                }

                ApprovalRequestStageDecision::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'approval_request_id' => $payload['approval_request_id'] ?? null,
                        'level' => $payload['level'] ?? 1,
                        'decision' => $payload['decision'] ?? '',
                        'acted_by_user_id' => $actedByUserId,
                        'acted_as_delegate_for_user_id' => $payload['acted_as_delegate_for_user_id'] ?? null,
                        'reason' => $payload['reason'] ?? null,
                        'sla_breached' => $payload['sla_breached'] ?? false,
                        'same_approver_as_prior_stage' => $payload['same_approver_as_prior_stage'] ?? false,
                        'acted_at' => $payload['acted_at'] ?? now()->toIso8601String(),
                    ]
                );
                break;
        }
    }

    protected function syncUser(string $uuid, array $payload, bool $trusted = true, ?User $actingUser = null): void
    {
        // Captured before updateOrCreate() below overwrites the row — this
        // is the ONLY place left that can still tell "is this a genuine
        // role CHANGE" from "the device just resent its already-correct
        // role," which matters for the check further down.
        $currentRole = User::find($uuid)?->getRoleNames()->first();

        // Defense in depth: the device is expected to hash PINs itself
        // before they ever reach a sync payload, but an older app build (or
        // any future write path) sending one in plain text must not land in
        // the database that way — hash it here rather than trust the client.
        $pinHash = $payload['pin_hash'] ?? null;
        if ($pinHash !== null && ! Hash::isHashed($pinHash)) {
            $pinHash = Hash::make($pinHash);
        }

        // 'role' isn't a users column — the actual role assignment happens
        // below via syncRoles(), against spatie's own role tables. And
        // 'biometric_enabled' isn't a column either: no migration ever
        // added it, and nothing reads it back anywhere in the app — it's
        // dead write-only data. Both used to ride along in this array
        // anyway; User::updateOrCreate() -> fill() silently drops whatever
        // isn't in $fillable under normal mass-assignment guarding, so it
        // never surfaced — until a call path that guarding doesn't apply
        // to (Laravel's SeedCommand runs entirely inside
        // Model::unguarded()) let both through straight to the INSERT and
        // failed on "Unknown column 'role'".
        $userData = [
            'business_id' => $payload['business_id'] ?? null,
            'name' => $payload['name'] ?? '',
            'email' => $payload['email'] ?? $uuid.'@pos.local',
            'pin_hash' => $pinHash,
            'is_active' => $payload['is_active'] ?? true,
        ];

        // Passwords are never synced from devices. New users get an unusable
        // random hash — BackOffice access requires a password set deliberately
        // (registration or the in-app reset), never a guessable default. On
        // updates the stored password is left untouched so a deliberately-set
        // password survives routine user syncs.
        if (! User::where('id', $uuid)->exists()) {
            $userData['password'] = Hash::make(Str::random(40));
        }

        $user = User::updateOrCreate(['id' => $uuid], $userData);

        if (method_exists($user, 'syncRoles') && isset($payload['role'])) {
            // Devices speak the short 'owner' role name (see
            // user_management_screen.dart's SegmentedButton); Spatie's
            // actual role record is 'business_owner', same as every other
            // inbound/outbound boundary in this app already translates
            // (UserController, BackOffice\UsersController). Without this,
            // syncRoles(['owner']) throws RoleDoesNotExist for guard 'web'.
            $incomingRole = $payload['role'] === 'owner' ? 'business_owner' : $payload['role'];

            // Critical privilege-escalation guard: BackOfficeController's
            // UsersController already gates every role assignment behind
            // authorizeManager() (MANAGE_USERS) before it ever reaches
            // process() — but this method is also the generic device sync
            // path, which had NO check at all. Without this, any
            // authenticated device (a cashier's own till included) could
            // push a 'users' upsert for its own id with
            // payload['role']='business_owner' and instantly grant itself
            // full owner access — reproduced live against a running server
            // before this fix existed. $trusted (server-authored writes:
            // seeders, BackOffice's own already-authorized call) bypasses
            // this the same way it bypasses the other approval gates in
            // this class; a device pushing back its own unchanged role is
            // also let through so routine syncs never spuriously fail.
            if (! $trusted && $incomingRole !== $currentRole) {
                $actingRole = $actingUser?->getRoleNames()->first();

                if (! app(BackOfficeAuthorizer::class)->can($userData['business_id'], $actingRole, BackOfficePermission::MANAGE_USERS)) {
                    throw new \RuntimeException('users: role changes require manage_users permission.');
                }
            }

            $user->syncRoles([$incomingRole]);
        }
    }

    /**
     * Recompute a product's stock_quantity as the sum of all its movements (legacy flat field).
     * Called after every stock_movement upsert and after every product upsert
     * so that concurrent pushes from multiple devices always converge.
     */
    protected function recomputeProductStock(string $productId): void
    {
        // Lock the product row for the lifetime of this recompute — without
        // it, two concurrent pushes recomputing the same product (e.g. two
        // stock_movements landing in overlapping transactions) can each read
        // the ledger sum, then both write, with the second write's SUM()
        // already stale relative to the first's own insert. The recompute is
        // idempotent/self-healing either way (a later recompute always
        // re-derives from the full ledger), so this closes a narrow
        // transient-staleness window rather than a correctness bug — see the
        // sync audit's stock-concurrency gap. No-op on drivers without row
        // locking (e.g. sqlite in tests), same as the rest of this codebase's
        // lockForUpdate() usage.
        Product::where('id', $productId)->lockForUpdate()->first();

        $computed = StockMovement::where('product_id', $productId)->sum('quantity_change');
        $updated = Product::where('id', $productId)
            ->whereExists(function ($q) use ($productId) {
                $q->from('stock_movements')->where('product_id', $productId);
            })
            ->update(['stock_quantity' => max(0, $computed)]);

        // Broadcast the authoritative recomputed total to every device (this
        // one included) — otherwise only the device that pushed the movement
        // ever learns the correct number, and every other till/warehouse
        // silently drifts until something else happens to touch this product.
        if ($updated > 0) {
            $product = Product::find($productId);
            if ($product) {
                $this->emitBroadcastSyncRecord('products', $product->id, $product->business_id, $this->productSyncPayload($product));
                $this->recordOversellIfNegative($product->business_id, $productId, null, (float) $computed);
            }
        }
    }

    /**
     * Recompute product_stock.quantity for a specific product+location from the movement ledger.
     * Called after location-aware stock_movement upserts.
     */
    protected function recomputeLocationStock(string $productId, string $locationId): void
    {
        // Same rationale as recomputeProductStock()'s lock above — locked on
        // the parent product row since a not-yet-existing product_stock row
        // (first movement for this product+location) has nothing to lock on
        // yet.
        Product::where('id', $productId)->lockForUpdate()->first();

        $computed = (float) DB::table('stock_movements')
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->sum('quantity_change');

        $stock = ProductStock::updateOrCreate(
            ['product_id' => $productId, 'location_id' => $locationId],
            ['quantity' => max(0, $computed)]
        );

        $product = Product::find($productId);
        if ($product) {
            // Full field snapshot, not just what this recompute touched — a
            // device applying this record does a full replace (same as this
            // class's own updateOrCreate calls elsewhere), so an incomplete
            // payload would silently wipe every other device's copy of this
            // location's threshold/price override and in-transit quantity.
            $this->emitBroadcastSyncRecord('product_stock', $stock->id, $product->business_id, [
                'product_id' => $stock->product_id,
                'location_id' => $stock->location_id,
                'quantity' => (float) $stock->quantity,
                'reserved_quantity' => (float) $stock->reserved_quantity,
                'in_transit_quantity' => (float) $stock->in_transit_quantity,
                'low_stock_threshold' => $stock->low_stock_threshold !== null ? (float) $stock->low_stock_threshold : null,
                'price_override' => $stock->price_override !== null ? (float) $stock->price_override : null,
                'updated_at' => $stock->updated_at?->toIso8601String(),
            ]);
            $this->recordOversellIfNegative($product->business_id, $productId, $locationId, $computed);
        }
    }

    /**
     * A movement-ledger recompute that sums below zero means two (or more)
     * devices oversold this product while offline — the stored/broadcast
     * quantity is still clamped to 0 by the callers above (so no device ever
     * displays or transacts against a negative number), but the shortfall
     * itself must not vanish silently. Flags it in stock_oversells for
     * manager review instead. Idempotent per open shortfall: a second
     * negative recompute against the same still-unresolved row updates it
     * in place rather than piling up duplicate rows for the same incident.
     */
    private function recordOversellIfNegative(?string $businessId, string $productId, ?string $locationId, float $computed): void
    {
        if ($computed >= 0 || ! $businessId) {
            return;
        }

        // Keyed on (business, product) only, not location — a single sale
        // spanning a location-aware movement fires BOTH recomputeLocationStock
        // and recomputeProductStock for the same underlying shortfall, and
        // those must collapse to one open incident, not two.
        $existing = StockOversell::where('business_id', $businessId)
            ->where('product_id', $productId)
            ->whereNull('resolved_at')
            ->first();

        if ($existing) {
            $existing->update([
                'location_id' => $existing->location_id ?? $locationId,
                'computed_quantity' => $computed,
                'shortfall' => abs($computed),
                'detected_at' => now(),
            ]);

            return;
        }

        StockOversell::create([
            'business_id' => $businessId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'computed_quantity' => $computed,
            'shortfall' => abs($computed),
            'detected_at' => now(),
        ]);
    }

    /**
     * Full field snapshot for a product, matching exactly what the device's
     * own push payload contains — a partial payload here would silently wipe
     * out name/price/etc. on every device that pulls it (the client upsert
     * defaults missing fields instead of leaving them untouched).
     */
    private function productSyncPayload(Product $product): array
    {
        return [
            'business_id' => $product->business_id,
            'category_id' => $product->category_id,
            'name' => $product->name,
            'item_type' => $product->item_type,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'price' => (float) $product->price,
            'min_price' => $product->min_price !== null ? (float) $product->min_price : null,
            'discount_percent' => $product->discount_percent !== null ? (float) $product->discount_percent : null,
            'cost_price' => (float) $product->cost_price,
            'deposit_amount' => $product->deposit_amount !== null ? (float) $product->deposit_amount : null,
            'unit' => $product->unit,
            'track_stock' => (bool) $product->track_stock,
            'stock_quantity' => (float) $product->stock_quantity,
            'low_stock_threshold' => (float) $product->low_stock_threshold,
            'image_path' => $product->image_path,
            'expiry_date' => $product->expiry_date?->toIso8601String(),
            'is_active' => (bool) $product->is_active,
        ];
    }

    /**
     * Writes a server-originated SyncRecord (device_id = null, so every
     * device — including the one that triggered the recompute — pulls it on
     * their next sync). Used for values the server derives rather than a
     * device sends directly, so the recomputed truth actually reaches
     * everyone instead of staying correct only in this database.
     */
    private function emitBroadcastSyncRecord(string $table, string $recordUuid, ?string $businessId, array $payload): void
    {
        SyncRecord::create([
            'business_id' => $businessId,
            'table_name' => $table,
            'record_uuid' => $recordUuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
            'device_id' => null,
        ]);
    }

    /**
     * Recompute purchase_orders.total_ordered and total_received from their line items.
     * Called after every purchase_order_items upsert so concurrent receiving from
     * multiple devices sums correctly instead of last-write-winning.
     */
    protected function recomputePurchaseOrderTotals(string $poId): void
    {
        $items = PurchaseOrderItem::where('purchase_order_id', $poId)->get();
        if ($items->isEmpty()) {
            return;
        }

        $totalOrdered = $items->sum(fn ($i) => $i->ordered_qty * $i->unit_cost);
        $totalReceived = $items->sum(function ($i) {
            $cost = $i->received_unit_cost ?? $i->unit_cost;

            return $i->received_qty * $cost;
        });

        PurchaseOrder::where('id', $poId)->update([
            'total_ordered' => $totalOrdered,
            'total_received' => $totalReceived,
        ]);
    }

    /**
     * Purchasing & Cash Vault Blueprint, part D — a PO over the business's
     * configured threshold is held at 'pending_approval' instead of moving
     * to 'sent', with a remote ApprovalRequest raised for an owner/manager
     * to clear (see PurchaseOrderApprovalGate). Only the first transition
     * into 'sent' (from null/'draft') is ever gated — once a PO has reached
     * pending_approval, this device's payload is a stale copy of the
     * original submission (it doesn't know a review is pending yet), so its
     * 'sent' claim is ignored rather than re-applied. Resolving the request
     * writes the final status directly (bypassing this method entirely —
     * see PurchaseOrderApprovalGate::resolve()), so there's no path back
     * into this gate once a decision has been made.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: bool} the status to persist, and whether this call just newly raised the gate
     */
    /**
     * @return array{0: string, 1: bool, 2: ?string} [status, justGated, gateReason]
     */
    /**
     * Server-side backstop for actions the spec requires a supervisor to
     * approve before they take effect: void/refund/exchange-rate-change.
     * The Flutter approval dialog (`requireApproval()`) already raises an
     * `approval_requests` row before executing the action locally, but that
     * row is just another sync record — a modified client (or a raw sync
     * payload) could otherwise push the resulting mutation directly with no
     * approval behind it at all, since none of these upserts previously
     * checked for one server-side.
     *
     * Matching is subject_id-first (the common case: the approval's subject
     * IS the record being upserted, e.g. voiding a transaction or changing
     * an exchange rate). A caller may instead pass an explicit
     * `approval_request_id` in $payload for the rare case where the upserted
     * row is a NEW record distinct from the approval's subject (a refund's
     * compensating transaction is a fresh uuid; the approval's subject is
     * the original sale) — that id is verified directly instead.
     *
     * @param  array<string, mixed>  $payload
     * @param  string[]  $actions
     */
    private function hasApprovedRequest(?string $businessId, string $subjectType, string $subjectId, array $actions, array $payload): bool
    {
        if (empty($businessId)) {
            return false;
        }

        $query = ApprovalRequest::where('business_id', $businessId)
            ->where('status', 'approved')
            ->whereIn('action', $actions);

        if (! empty($payload['approval_request_id'])) {
            return (clone $query)->where('id', $payload['approval_request_id'])
                ->where('subject_type', $subjectType)
                ->exists();
        }

        return $query->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->exists();
    }

    /**
     * Whether an inbound chart-of-accounts row (gl_accounts or
     * account_sub_categories) either doesn't exist yet (a create) or
     * differs from what's currently stored (a change) — a device resending
     * its own already-current row untouched must not trip the owner/manager
     * gate, or routine resyncs would spuriously fail.
     *
     * @param  array<string, mixed>  $incoming
     */
    private function chartOfAccountsRowChanged(?Model $existing, array $incoming): bool
    {
        if ($existing === null) {
            return true;
        }

        foreach ($incoming as $field => $value) {
            if ($existing->getAttribute($field) != $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Shared fraud guard for the chart-of-accounts sync cases — same role
     * floor as 'bank_accounts' (Permission.manageCashVault: owner or
     * manager), since gl_accounts creation is currently also reachable via
     * bank-account creation and must keep working for a manager, not just
     * an owner.
     */
    private function requireOwnerOrManager(?User $actingUser, string $context): void
    {
        $actingRole = $actingUser?->getRoleNames()->first();

        if (! in_array($actingRole, ['business_owner', 'manager'], true)) {
            throw new \RuntimeException("{$context} requires the owner or manager role.");
        }
    }

    /**
     * STC·08 — decides flagged_for_recount/recount_completed_at for one
     * stock_take_items upsert. See StockTakeItem::needsRecount() for how
     * this gets enforced at approval time.
     *
     * @return array{0: bool, 1: ?Carbon} [flagged, recountCompletedAt]
     */
    private function resolveStockTakeRecountState(?StockTakeItem $existing, ?string $stockTakeId, float $systemQty, ?float $incomingCounted): array
    {
        // Already satisfied once — never re-litigate on a later edit, even
        // if that edit happens to drift the variance again.
        if ($existing?->recount_completed_at !== null) {
            return [true, $existing->recount_completed_at];
        }

        // No count in this payload at all — nothing new to evaluate, keep
        // whatever flag state (if any) already exists.
        if ($incomingCounted === null) {
            return [(bool) $existing?->flagged_for_recount, null];
        }

        $wasFlagged = (bool) $existing?->flagged_for_recount;
        $countChanged = $existing === null || $existing->counted_qty === null
            || abs((float) $existing->counted_qty - $incomingCounted) > 0.0001;

        if ($wasFlagged && $countChanged) {
            // This IS the recount the flag was waiting for — satisfied
            // regardless of whether the new count is itself still off,
            // which is then a judgment call for whoever approves the take.
            return [true, now()];
        }

        $threshold = $stockTakeId ? $this->stockTakeVarianceThreshold($stockTakeId) : null;
        if ($threshold === null) {
            return [$wasFlagged, null];
        }

        $variancePercent = $systemQty > 0
            ? abs($incomingCounted - $systemQty) / $systemQty * 100
            : ($incomingCounted > 0 ? 100.0 : 0.0);

        return [$variancePercent > $threshold, null];
    }

    private function stockTakeVarianceThreshold(string $stockTakeId): ?float
    {
        $businessId = StockTake::where('id', $stockTakeId)->value('business_id');

        return $businessId ? Business::find($businessId)?->stockTakeVarianceThresholdPercent() : null;
    }

    private function gatePurchaseOrderStatus(?PurchaseOrder $existing, array $payload): array
    {
        $incomingStatus = $payload['status'] ?? 'draft';

        if ($existing?->status === 'pending_approval') {
            return ['pending_approval', false, null];
        }

        if (! PurchaseOrder::isValidTransition($existing?->status, $incomingStatus)) {
            throw new \RuntimeException(
                "Invalid purchase order transition: '{$existing?->status}' -> '{$incomingStatus}'"
            );
        }

        $isFirstSubmission = $incomingStatus === 'sent' && ($existing === null || $existing->status === 'draft');
        if (! $isFirstSubmission) {
            return [$incomingStatus, false, null];
        }

        $businessId = $payload['business_id'] ?? null;
        $threshold = Business::find($businessId)?->poApprovalThreshold();
        $totalOrdered = (float) ($payload['total_ordered'] ?? 0);

        // A PO with no creator can never raise a properly-attributed
        // ApprovalRequest (requested_by_user_id and po_audit_logs.user_id
        // both require a real user) — gating it anyway would strand it at
        // pending_approval with nothing able to resolve it, so it's left
        // ungated instead of risking that dead end.
        $hasCreator = ! empty($payload['created_by_user_id']);

        if ($threshold !== null && $totalOrdered > $threshold && $hasCreator) {
            return ['pending_approval', true, "total exceeds this business's configured PO threshold"];
        }

        // PUR·02 — a period procurement budget catches what the flat
        // per-PO threshold above can't: many individually-small POs that
        // add up past what's been allocated for the period. Checked
        // against spend excluding this PO (it isn't 'sent' yet at this
        // point) plus its own total, so the PO that actually tips the
        // balance is the one that gets held.
        if ($hasCreator && $businessId) {
            $budget = ProcurementBudget::activeFor($businessId, now());
            if ($budget && $budget->spentSoFar() + $totalOrdered > (float) $budget->amount) {
                return ['pending_approval', true, "would exceed the '{$budget->name}' procurement budget for this period"];
            }
        }

        return [$incomingStatus, false, null];
    }

    /**
     * Recompute a customer's loyalty_points and credit_balance from their ledger tables.
     * Called after every loyalty_transaction and credit_transaction upsert.
     */
    protected function recomputeCustomerBalances(string $customerId): void
    {
        $loyaltyPoints = LoyaltyTransaction::where('customer_id', $customerId)->sum('points');
        $creditBalance = CreditTransaction::where('customer_id', $customerId)->sum('amount');

        Customer::where('id', $customerId)->update([
            'loyalty_points' => max(0, $loyaltyPoints),
            'credit_balance' => max(0, $creditBalance),
        ]);
    }

    /**
     * Recompute an invoice's amount_paid from its actual invoice_payments
     * ledger, the same "never trust a payload's claim about a
     * ledger-derived total" principle as recomputeCustomerBalances()/
     * recomputeProductStock() above. Without this, 'invoices' upserts
     * accepted amount_paid straight from any device's payload — reproduced
     * live: a plain cashier's device marked a real $13,507.40 invoice
     * "paid" in a single push, with no new invoice_payments row and no
     * money ever collected, silently erasing $7,294 of real receivable
     * from every report that reads amount_paid/status. Called after both
     * an 'invoices' upsert and an 'invoice_payments' upsert, so a genuine
     * new payment is reflected immediately either way.
     */
    protected function recomputeInvoiceAmountPaid(string $invoiceId): void
    {
        $amountPaid = max(0, InvoicePayment::where('invoice_id', $invoiceId)->sum('base_equivalent'));
        $invoice = Invoice::find($invoiceId);
        if (! $invoice) {
            return;
        }

        $update = ['amount_paid' => $amountPaid];

        // Keep status consistent with the now-corrected amount, but only
        // when status is itself payment-derived — never override a
        // lifecycle state (draft/sent/cancelled) that has nothing to do
        // with how much has been paid.
        if (in_array($invoice->status, ['paid', 'partial'], true)) {
            $update['status'] = $amountPaid >= (float) $invoice->total ? 'paid' : 'partial';
        }

        $invoice->update($update);
    }

    /**
     * Live-verification finding: expected_cash/counted_cash/variance/
     * total_sales/cash_sales/card_sales/mobile_money_sales/credit_sales/
     * total_refunds/total_discounts/transaction_count were all accepted
     * straight from a device's own shift-close payload with no independent
     * server-side derivation — the same "self-reported financial figure, no
     * ledger check" shape as the invoices.amount_paid fraud fixed in
     * recomputeInvoiceAmountPaid() above. Enables classic till-skimming:
     * report a lower total_sales/expected_cash than the shift's real
     * transactions add up to, so pocketing the difference never shows as a
     * variance. counted_cash itself (a physical cash count nobody else can
     * verify) is left as reported; every other figure is derived here from
     * the real Transaction/Payment/ContainerDepositLedger/ChangeOwedLedger
     * rows, mirroring shift_close_provider.dart's shiftSummaryProvider /
     * confirmAndCloseShift() exactly (same statuses, same cash-out
     * subtractions) so the two never drift apart. Scoped by cashier + time
     * window + location, not a shift_id column — transactions have no such
     * column, and the Flutter client itself doesn't scope by one either.
     */
    protected function recomputeShiftFigures(string $shiftId): void
    {
        $shift = Shift::find($shiftId);
        if ($shift === null || $shift->cashier_id === null || $shift->opened_at === null) {
            return;
        }

        $windowEnd = $shift->closed_at ?? now();

        $transactions = Transaction::where('business_id', $shift->business_id)
            ->where('user_id', $shift->cashier_id)
            ->where('created_at', '>=', $shift->opened_at)
            ->where('created_at', '<=', $windowEnd)
            ->when($shift->location_id, fn ($q) => $q->where('location_id', $shift->location_id))
            ->get();

        // Same classification as shiftSummaryProvider: a refund is a
        // separate negative-total reversal transaction, not an edit of the
        // original — the original (status flipped to refunded/
        // partial_refund) still belongs in gross; only a negative-total row
        // is a reversal.
        $originalSaleStatuses = ['completed', 'refunded', 'partial_refund'];
        $originalSales = $transactions->filter(
            fn (Transaction $t) => (float) $t->total >= 0 && in_array($t->status, $originalSaleStatuses, true)
        );
        $reversals = $transactions->filter(fn (Transaction $t) => (float) $t->total < 0);
        $completed = $transactions->filter(fn (Transaction $t) => $t->status === 'completed');

        $grossSales = $originalSales->sum(fn (Transaction $t) => (float) $t->total);
        $refundTotal = $reversals->sum(fn (Transaction $t) => abs((float) $t->total));
        $discountTotal = $originalSales->sum(fn (Transaction $t) => (float) $t->discount_total);

        $cashSales = 0.0;
        $cardSales = 0.0;
        $mobileSales = 0.0;
        $creditSales = 0.0;
        // Payment-method breakdown deliberately scoped to `completed` only,
        // same as the client — a refund's cash/card payout has no Payments
        // row of its own, so widening this to refunded/partial_refund
        // originals would count cash that's since left the till.
        $payments = Payment::whereIn('transaction_id', $completed->pluck('id'))->get();
        foreach ($payments as $payment) {
            $method = strtolower($payment->method);
            $amount = (float) $payment->base_equivalent;
            if (str_contains($method, 'cash')) {
                $cashSales += $amount;
            } elseif (str_contains($method, 'card') || str_contains($method, 'swipe')) {
                $cardSales += $amount;
            } elseif (str_contains($method, 'mobile') || str_contains($method, 'ecocash')
                || str_contains($method, 'm-pesa') || str_contains($method, 'mpesa')) {
                $mobileSales += $amount;
            } elseif (str_contains($method, 'credit')) {
                $creditSales += $amount;
            }
        }

        $depositRefundsCash = (float) ContainerDepositLedger::where('business_id', $shift->business_id)
            ->where('user_id', $shift->cashier_id)
            ->where('type', 'return')
            ->where('refund_method', 'cash')
            ->where('created_at', '>=', $shift->opened_at)
            ->where('created_at', '<=', $windowEnd)
            ->get()
            ->sum(fn (ContainerDepositLedger $l) => abs((float) $l->quantity) * (float) $l->deposit_amount_per_unit);

        $changeClaimsCash = (float) ChangeOwedLedger::where('business_id', $shift->business_id)
            ->where('user_id', $shift->cashier_id)
            ->where('type', 'claim')
            ->where('created_at', '>=', $shift->opened_at)
            ->where('created_at', '<=', $windowEnd)
            ->get()
            ->sum(fn (ChangeOwedLedger $l) => abs((float) $l->amount));

        $expectedCash = (float) $shift->opening_float + $cashSales - $depositRefundsCash - $changeClaimsCash;

        $shift->forceFill([
            'total_sales' => $grossSales,
            'cash_sales' => $cashSales,
            'card_sales' => $cardSales,
            'mobile_money_sales' => $mobileSales,
            'credit_sales' => $creditSales,
            'total_refunds' => $refundTotal,
            'total_discounts' => $discountTotal,
            'transaction_count' => $completed->count(),
            'expected_cash' => $expectedCash,
            'variance' => $shift->counted_cash !== null ? ((float) $shift->counted_cash - $expectedCash) : null,
        ])->save();
    }

    protected function handleDelete(string $table, string $uuid): void
    {
        if (in_array($table, self::IMMUTABLE)) {
            return;
        }

        // Model::where(...)->delete() below is a bulk query-builder delete —
        // it never fires ProjectMilestone::booted()'s cascade, so a deleted
        // milestone's tasks would otherwise linger as orphans. Delete them
        // explicitly before falling into the generic path.
        if ($table === 'project_milestones') {
            MilestoneTask::where('milestone_id', $uuid)->delete();
            ProjectMilestone::where('id', $uuid)->delete();

            return;
        }

        $modelMap = [
            'businesses' => Business::class,
            'locations' => Location::class,
            'categories' => Category::class,
            'units_of_measure' => UnitOfMeasure::class,
            'tax_rates' => TaxRate::class,
            'exchange_rates' => ExchangeRate::class,
            'products' => Product::class,
            'bundles' => Bundle::class,
            'bundle_items' => BundleItem::class,
            'product_container_links' => ProductContainerLink::class,
            'product_variants' => ProductVariant::class,
            'customers' => Customer::class,
            'transactions' => Transaction::class,
            'suppliers' => Supplier::class,
            'purchase_orders' => PurchaseOrder::class,
            'stock_transfers' => StockTransfer::class,
            'coupons' => Coupon::class,
            'shifts' => Shift::class,
            'expenses' => Expense::class,
            'stock_takes' => StockTake::class,
            'employees' => Employee::class,
            'salary_payments' => SalaryPayment::class,
            'tills' => Till::class,
            'quotations' => Quotation::class,
            'quotation_items' => QuotationItem::class,
            'invoices' => Invoice::class,
            'invoice_items' => InvoiceItem::class,
            'credit_notes' => CreditNote::class,
            'supplier_invoices' => SupplierInvoice::class,
            'supplier_credit_notes' => SupplierCreditNote::class,
            'supplier_reconciliations' => SupplierReconciliation::class,
            'supplier_banks' => SupplierBank::class,
            'recurring_invoice_schedules' => RecurringInvoiceSchedule::class,
            'product_units' => ProductUnit::class,
            'product_price_tiers' => ProductPriceTier::class,
            'procurement_budgets' => ProcurementBudget::class,
            'milestone_tasks' => MilestoneTask::class,
            'approval_rule_sets' => ApprovalRuleSet::class,
            'approval_rules' => ApprovalRule::class,
            'approval_groups' => ApprovalGroup::class,
            'approval_group_members' => ApprovalGroupMember::class,
            'approval_delegations' => ApprovalDelegation::class,
        ];

        $softDeleteIsActive = ['locations', 'categories', 'units_of_measure', 'tax_rates', 'products', 'product_variants', 'suppliers', 'supplier_banks', 'coupons', 'tills'];

        if (isset($modelMap[$table])) {
            if (in_array($table, $softDeleteIsActive)) {
                $modelMap[$table]::where('id', $uuid)->update(['is_active' => false]);
            } elseif ($table === 'employees') {
                $modelMap[$table]::where('id', $uuid)->update(['status' => 'inactive']);
            } else {
                $modelMap[$table]::where('id', $uuid)->delete();
            }
        }

        // Special case: product_tax_rates composite key
        if ($table === 'product_tax_rates') {
            $parts = explode('|', $uuid);
            if (count($parts) === 2) {
                ProductTaxRate::where('product_id', $parts[0])
                    ->where('tax_rate_id', $parts[1])
                    ->delete();
            }
        }

        // Special case: product_sellable_locations composite key
        if ($table === 'product_sellable_locations') {
            $parts = explode('|', $uuid);
            if (count($parts) === 2) {
                ProductSellableLocation::where('product_id', $parts[0])
                    ->where('location_id', $parts[1])
                    ->delete();
            }
        }
    }
}
