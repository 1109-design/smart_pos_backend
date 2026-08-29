<?php

namespace App\Services;

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
use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Location;
use App\Models\LoyaltyTransaction;
use App\Models\Payment;
use App\Models\PoAuditLog;
use App\Models\Product;
use App\Models\ProductContainerLink;
use App\Models\ProductStock;
use App\Models\ProductTaxRate;
use App\Models\ProductVariant;
use App\Models\ProductVariantStock;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\RecurringInvoiceSchedule;
use App\Models\RolePermission;
use App\Models\SalaryPayment;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\SyncRecord;
use App\Models\TaxRate;
use App\Models\Till;
use App\Models\TillCashMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionTax;
use App\Models\User;
use App\Services\Zimra\ZimraSalesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SyncProcessor
{
    // Tables whose records are immutable — delete operations are ignored
    private const IMMUTABLE = [
        'stock_movements', 'loyalty_transactions', 'credit_transactions',
        'transaction_items', 'transaction_taxes', 'payments', 'po_audit_logs',
        'container_deposit_ledger', 'change_owed_ledger', 'till_cash_movements',
        'invoice_payments', 'credit_note_items',
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
        'users' => User::class,
        'container_deposit_ledger' => ContainerDepositLedger::class,
        'change_owed_ledger' => ChangeOwedLedger::class,
        'tills' => Till::class,
        'till_cash_movements' => TillCashMovement::class,
        'quotations' => Quotation::class,
        'invoices' => Invoice::class,
        'credit_notes' => CreditNote::class,
        'recurring_invoice_schedules' => RecurringInvoiceSchedule::class,
    ];

    // Child tables scoped only through a parent record: table => [own model,
    // own FK column pointing at the parent]. assertOwnership() resolves each
    // parent id to a business_id via resolveParentOwner() below.
    private const CHILD_SCOPED_MODELS = [
        'product_stock' => [ProductStock::class, 'product_id'],
        'product_variants' => [ProductVariant::class, 'product_id'],
        'product_variant_stock' => [ProductVariantStock::class, 'variant_id'],
        'stock_transfer_items' => [StockTransferItem::class, 'stock_transfer_id'],
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
    ];

    // Deliberately unguarded, and why:
    //  - currencies: shared reference data, keyed by currency code, not owned by any one business.
    //  - role_permissions: updateOrCreate() is keyed on (business_id, role) together, so a mismatched
    //    business_id can only create/update the caller's OWN row — it can never touch another
    //    tenant's row of the same role name. Safe by construction.

    public function process(string $table, string $uuid, string $operation, array $payload): void
    {
        $this->assertOwnership($table, $uuid, $payload);

        if ($operation === 'delete') {
            $this->handleDelete($table, $uuid);

            return;
        }

        $this->handleUpsert($table, $uuid, $payload);
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
            if ($targetOwner === null || (string) $targetOwner !== (string) $businessId) {
                throw new \RuntimeException("{$table}: referenced parent does not belong to this business.");
            }
        }
    }

    protected function resolveParentOwner(string $table, string $parentId): ?string
    {
        return match ($table) {
            'product_stock', 'product_variants' => Product::where('id', $parentId)->value('business_id'),
            'product_variant_stock' => Product::query()
                ->join('product_variants', 'product_variants.product_id', '=', 'products.id')
                ->where('product_variants.id', $parentId)
                ->value('products.business_id'),
            'stock_transfer_items' => StockTransfer::where('id', $parentId)->value('business_id'),
            'bundle_items' => Bundle::where('id', $parentId)->value('business_id'),
            'product_container_links' => Product::where('id', $parentId)->value('business_id'),
            'transaction_items', 'transaction_taxes', 'payments' => Transaction::where('id', $parentId)->value('business_id'),
            'loyalty_transactions', 'credit_transactions' => Customer::where('id', $parentId)->value('business_id'),
            'purchase_order_items', 'po_audit_logs' => PurchaseOrder::where('id', $parentId)->value('business_id'),
            'stock_take_items' => StockTake::where('id', $parentId)->value('business_id'),
            'quotation_items' => Quotation::where('id', $parentId)->value('business_id'),
            'invoice_items', 'invoice_payments' => Invoice::where('id', $parentId)->value('business_id'),
            'credit_note_items' => CreditNote::where('id', $parentId)->value('business_id'),
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

    protected function handleUpsert(string $table, string $uuid, array $payload): void
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
                StockTransfer::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'transfer_number' => $payload['transfer_number'] ?? '',
                        'from_location_id' => $payload['from_location_id'] ?? null,
                        'to_location_id' => $payload['to_location_id'] ?? null,
                        'status' => $payload['status'] ?? 'pending',
                        'notes' => $payload['notes'] ?? null,
                        'requested_by_user_id' => $payload['requested_by_user_id'] ?? null,
                        'approved_by_user_id' => $payload['approved_by_user_id'] ?? null,
                        'approved_at' => $payload['approved_at'] ?? null,
                        'dispatched_at' => $payload['dispatched_at'] ?? null,
                        'received_at' => $payload['received_at'] ?? null,
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
                Business::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'name' => $payload['name'] ?? '',
                        'address' => $payload['address'] ?? null,
                        'phone' => $payload['phone'] ?? null,
                        'email' => $payload['email'] ?? null,
                        'tax_number' => $payload['vat_number'] ?? $payload['tax_number'] ?? null,
                        'tin' => $payload['tin'] ?? null,
                        'currency_code' => $payload['base_currency_code'] ?? 'USD',
                        'logo_path' => $payload['logo_path'] ?? null,
                        'metadata' => $payload['metadata'] ?? null,
                        'fiscalisation_enabled' => $payload['fiscalisation_enabled'] ?? false,
                        'day_shift_start' => $payload['day_shift_start'] ?? null,
                        'night_shift_start' => $payload['night_shift_start'] ?? null,
                    ]
                );
                break;

            case 'users':
                $this->syncUser($uuid, $payload);
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
                        'track_stock' => $payload['track_stock'] ?? true,
                        // stock_quantity is accepted from payload for initial setup.
                        // It will be overridden below if movements exist (multi-device safe).
                        'stock_quantity' => $payload['stock_quantity'] ?? 0,
                        'low_stock_threshold' => $payload['low_stock_threshold'] ?? 5,
                        'image_path' => $payload['image_path'] ?? null,
                        'expiry_date' => $payload['expiry_date'] ?? null,
                        'is_active' => $payload['is_active'] ?? true,
                    ]
                );
                // First time this product is created with an opening quantity: give it
                // a ledger entry, same as every other stock change, so take-on has an
                // audit trail instead of being a bare column write nothing can trace.
                // An optional location_id (set by location-aware callers, e.g. the
                // BackOffice create form) attributes the opening quantity to that
                // location too, instead of leaving it in the flat total only.
                if (! $productExisted && $openingStock != 0) {
                    StockMovement::create([
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'product_id' => $uuid,
                        'type' => 'opening_stock',
                        'quantity_change' => $openingStock,
                        'reason' => 'Opening stock (take-on)',
                        'user_id' => $payload['user_id'] ?? null,
                    ]);
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
                $txData = [
                    'business_id' => $payload['business_id'] ?? null,
                    'location_id' => $payload['location_id'] ?? null,
                    'user_id' => $payload['user_id'] ?? null,
                    'customer_id' => $payload['customer_id'] ?? null,
                    'subtotal' => $payload['subtotal'] ?? 0,
                    'tax_total' => $payload['tax_total'] ?? 0,
                    'discount_total' => $payload['discount_total'] ?? 0,
                    'deposit_total' => $payload['deposit_total'] ?? 0,
                    'surcharge_total' => $payload['surcharge_total'] ?? 0,
                    'total' => $payload['total'] ?? 0,
                    'base_currency' => $payload['base_currency'] ?? 'USD',
                    'status' => $payload['status'] ?? 'completed',
                    'sale_number' => $payload['sale_number'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                ];
                if (! $txExists && isset($payload['created_at'])) {
                    // Preserve the device's sale timestamp on first insert
                    $txData['created_at'] = $payload['created_at'];
                }
                $tx = Transaction::updateOrCreate(['id' => $uuid], $txData);

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
                    ]
                );
                break;

            case 'stock_movements':
                StockMovement::updateOrCreate(
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
                CreditTransaction::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'customer_id' => $payload['customer_id'] ?? null,
                        'transaction_id' => $payload['transaction_id'] ?? null,
                        'amount' => $payload['amount'] ?? 0,
                        'type' => $payload['type'] ?? 'purchase',
                        'method' => $payload['method'] ?? null,
                        'reference' => $payload['reference'] ?? null,
                    ]
                );
                // Recompute customer credit_balance from the full ledger.
                if (! empty($payload['customer_id'])) {
                    $this->recomputeCustomerBalances($payload['customer_id']);
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
                    ]
                );
                break;

            case 'purchase_orders':
                PurchaseOrder::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'receiving_location_id' => $payload['receiving_location_id'] ?? null,
                        'supplier_id' => $payload['supplier_id'] ?? null,
                        'supplier_name' => $payload['supplier_name'] ?? null,
                        'po_number' => $payload['po_number'] ?? '',
                        'status' => $payload['status'] ?? 'draft',
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
                Till::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
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
                        'reason' => $payload['reason'] ?? null,
                        'recorded_by_user_id' => $payload['recorded_by_user_id'] ?? null,
                    ]
                );
                break;

            case 'shifts':
                Shift::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'till_id' => $payload['till_id'] ?? null,
                        'cashier_id' => $payload['cashier_id'] ?? null,
                        'opened_at' => $payload['opened_at'] ?? now(),
                        'closed_at' => $payload['closed_at'] ?? null,
                        'status' => $payload['status'] ?? 'open',
                        'opening_float' => $payload['opening_float'] ?? 0,
                        'expected_cash' => $payload['expected_cash'] ?? null,
                        'counted_cash' => $payload['counted_cash'] ?? null,
                        'variance' => $payload['variance'] ?? null,
                        'total_sales' => $payload['total_sales'] ?? null,
                        'cash_sales' => $payload['cash_sales'] ?? null,
                        'card_sales' => $payload['card_sales'] ?? null,
                        'mobile_money_sales' => $payload['mobile_money_sales'] ?? null,
                        'credit_sales' => $payload['credit_sales'] ?? null,
                        'total_refunds' => $payload['total_refunds'] ?? null,
                        'total_discounts' => $payload['total_discounts'] ?? null,
                        'transaction_count' => $payload['transaction_count'] ?? null,
                        'opening_float_json' => $payload['opening_float_json'] ?? null,
                        'counted_cash_json' => $payload['counted_cash_json'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                    ]
                );
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

                Expense::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'recorded_by_user_id' => $recordedByUserId,
                        'category' => $payload['category'] ?? '',
                        'description' => $payload['description'] ?? null,
                        'amount' => $payload['amount'] ?? 0,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'base_equivalent' => $payload['base_equivalent'] ?? 0,
                        'exchange_rate' => $payload['exchange_rate'] ?? 1,
                        'payment_method' => $payload['payment_method'] ?? 'cash',
                        'mobile_provider' => $payload['mobile_provider'] ?? null,
                        'payment_reference' => $payload['payment_reference'] ?? null,
                        'receipt_path' => $payload['receipt_path'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'expense_date' => $payload['expense_date'] ?? now(),
                        'deleted_at' => $payload['deleted_at'] ?? null,
                    ]
                );
                break;

            case 'stock_takes':
                $currentStatus = StockTake::where('id', $uuid)->value('status');
                $incomingStatus = $payload['status'] ?? 'draft';

                if (! StockTake::isValidTransition($currentStatus, $incomingStatus)) {
                    throw new \RuntimeException(
                        "Invalid stock take transition: '{$currentStatus}' -> '{$incomingStatus}'"
                    );
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
                StockTakeItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'stock_take_id' => $payload['stock_take_id'] ?? null,
                        'product_id' => $payload['product_id'] ?? null,
                        'product_name' => $payload['product_name'] ?? '',
                        'system_qty' => $payload['system_qty'] ?? 0,
                        'counted_qty' => $payload['counted_qty'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                    ]
                );
                break;

            case 'role_permissions':
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
                SalaryPayment::updateOrCreate(
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
                    ]
                );
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
                    ]
                );
                break;

            case 'invoices':
                Invoice::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'quotation_id' => $payload['quotation_id'] ?? null,
                        'invoice_number' => $payload['invoice_number'] ?? '',
                        'type' => $payload['type'] ?? 'standard',
                        'status' => $payload['status'] ?? 'draft',
                        'issue_date' => $payload['issue_date'] ?? now(),
                        'due_date' => $payload['due_date'] ?? null,
                        'payment_terms_days' => $payload['payment_terms_days'] ?? 0,
                        'subtotal' => $payload['subtotal'] ?? 0,
                        'discount_total' => $payload['discount_total'] ?? 0,
                        'tax_total' => $payload['tax_total'] ?? 0,
                        'deposit_required' => $payload['deposit_required'] ?? 0,
                        'total' => $payload['total'] ?? 0,
                        'amount_paid' => $payload['amount_paid'] ?? 0,
                        'recurring_schedule_id' => $payload['recurring_schedule_id'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'created_by_user_id' => $payload['created_by_user_id'] ?? null,
                    ]
                );
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
                InvoicePayment::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'invoice_id' => $payload['invoice_id'] ?? null,
                        'method' => $payload['method'] ?? 'cash',
                        'amount' => $payload['amount'] ?? 0,
                        'currency_code' => $payload['currency_code'] ?? 'USD',
                        'base_equivalent' => $payload['base_equivalent'] ?? 0,
                        'recorded_by_user_id' => $payload['recorded_by_user_id'] ?? null,
                        'paid_at' => $payload['paid_at'] ?? now(),
                    ]
                );
                break;

            case 'credit_notes':
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
        }
    }

    protected function syncUser(string $uuid, array $payload): void
    {
        // Defense in depth: the device is expected to hash PINs itself
        // before they ever reach a sync payload, but an older app build (or
        // any future write path) sending one in plain text must not land in
        // the database that way — hash it here rather than trust the client.
        $pinHash = $payload['pin_hash'] ?? null;
        if ($pinHash !== null && ! Hash::isHashed($pinHash)) {
            $pinHash = Hash::make($pinHash);
        }

        $userData = [
            'business_id' => $payload['business_id'] ?? null,
            'name' => $payload['name'] ?? '',
            'email' => $payload['email'] ?? $uuid.'@pos.local',
            'pin_hash' => $pinHash,
            'role' => $payload['role'] ?? 'cashier',
            'is_active' => $payload['is_active'] ?? true,
            'biometric_enabled' => $payload['biometric_enabled'] ?? false,
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
            $user->syncRoles([$payload['role']]);
        }
    }

    /**
     * Recompute a product's stock_quantity as the sum of all its movements (legacy flat field).
     * Called after every stock_movement upsert and after every product upsert
     * so that concurrent pushes from multiple devices always converge.
     */
    protected function recomputeProductStock(string $productId): void
    {
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
            }
        }
    }

    /**
     * Recompute product_stock.quantity for a specific product+location from the movement ledger.
     * Called after location-aware stock_movement upserts.
     */
    protected function recomputeLocationStock(string $productId, string $locationId): void
    {
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
            $this->emitBroadcastSyncRecord('product_stock', $stock->id, $product->business_id, [
                'product_id' => $stock->product_id,
                'location_id' => $stock->location_id,
                'quantity' => (float) $stock->quantity,
                'reserved_quantity' => (float) $stock->reserved_quantity,
                'updated_at' => $stock->updated_at?->toIso8601String(),
            ]);
        }
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

    protected function handleDelete(string $table, string $uuid): void
    {
        if (in_array($table, self::IMMUTABLE)) {
            return;
        }

        $modelMap = [
            'businesses' => Business::class,
            'locations' => Location::class,
            'categories' => Category::class,
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
            'recurring_invoice_schedules' => RecurringInvoiceSchedule::class,
        ];

        $softDeleteIsActive = ['locations', 'categories', 'tax_rates', 'products', 'product_variants', 'suppliers', 'coupons', 'tills'];

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
    }
}
