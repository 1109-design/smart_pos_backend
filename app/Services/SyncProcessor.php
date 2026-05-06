<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CreditTransaction;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Location;
use App\Models\SalaryPayment;
use App\Models\LoyaltyTransaction;
use App\Models\Payment;
use App\Models\PoAuditLog;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductTaxRate;
use App\Models\ProductVariant;
use App\Models\ProductVariantStock;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\RolePermission;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionTax;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SyncProcessor
{
    // Tables whose records are immutable — delete operations are ignored
    private const IMMUTABLE = [
        'stock_movements', 'loyalty_transactions', 'credit_transactions',
        'transaction_items', 'transaction_taxes', 'payments', 'po_audit_logs',
    ];

    public function process(string $table, string $uuid, string $operation, array $payload): void
    {
        if ($operation === 'delete') {
            $this->handleDelete($table, $uuid);

            return;
        }

        $this->handleUpsert($table, $uuid, $payload);
    }

    protected function handleUpsert(string $table, string $uuid, array $payload): void
    {
        switch ($table) {
            case 'locations':
                Location::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'parent_id'   => $payload['parent_id'] ?? null,
                        'name'        => $payload['name'] ?? '',
                        'type'        => $payload['type'] ?? 'shop',
                        'address'     => $payload['address'] ?? null,
                        'phone'       => $payload['phone'] ?? null,
                        'email'       => $payload['email'] ?? null,
                        'can_sell'    => $payload['can_sell'] ?? true,
                        'can_receive' => $payload['can_receive'] ?? true,
                        'is_active'   => $payload['is_active'] ?? true,
                    ]
                );
                break;

            case 'product_stock':
                ProductStock::updateOrCreate(
                    ['product_id' => $payload['product_id'] ?? $uuid, 'location_id' => $payload['location_id'] ?? ''],
                    [
                        'id'                => $uuid,
                        'quantity'          => $payload['quantity'] ?? 0,
                        'reserved_quantity' => $payload['reserved_quantity'] ?? 0,
                    ]
                );
                break;

            case 'product_variant_stock':
                ProductVariantStock::updateOrCreate(
                    ['variant_id' => $payload['variant_id'] ?? $uuid, 'location_id' => $payload['location_id'] ?? ''],
                    [
                        'id'                => $uuid,
                        'quantity'          => $payload['quantity'] ?? 0,
                        'reserved_quantity' => $payload['reserved_quantity'] ?? 0,
                    ]
                );
                break;

            case 'stock_transfers':
                StockTransfer::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id'          => $payload['business_id'] ?? null,
                        'transfer_number'      => $payload['transfer_number'] ?? '',
                        'from_location_id'     => $payload['from_location_id'] ?? null,
                        'to_location_id'       => $payload['to_location_id'] ?? null,
                        'status'               => $payload['status'] ?? 'pending',
                        'notes'                => $payload['notes'] ?? null,
                        'requested_by_user_id' => $payload['requested_by_user_id'] ?? null,
                        'approved_by_user_id'  => $payload['approved_by_user_id'] ?? null,
                        'approved_at'          => $payload['approved_at'] ?? null,
                        'dispatched_at'        => $payload['dispatched_at'] ?? null,
                        'received_at'          => $payload['received_at'] ?? null,
                    ]
                );
                break;

            case 'stock_transfer_items':
                StockTransferItem::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'stock_transfer_id' => $payload['stock_transfer_id'] ?? null,
                        'product_id'        => $payload['product_id'] ?? null,
                        'variant_id'        => $payload['variant_id'] ?? null,
                        'product_name'      => $payload['product_name'] ?? '',
                        'qty_requested'     => $payload['qty_requested'] ?? 0,
                        'qty_sent'          => $payload['qty_sent'] ?? 0,
                        'qty_received'      => $payload['qty_received'] ?? 0,
                        'notes'             => $payload['notes'] ?? null,
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
                        'currency_code' => $payload['base_currency_code'] ?? 'USD',
                        'logo_path' => $payload['logo_path'] ?? null,
                        'metadata' => $payload['metadata'] ?? null,
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
                $product = Product::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'category_id' => $payload['category_id'] ?? null,
                        'name' => $payload['name'] ?? '',
                        'sku' => $payload['sku'] ?? null,
                        'barcode' => $payload['barcode'] ?? null,
                        'price' => $payload['price'] ?? 0,
                        'min_price' => $payload['min_price'] ?? null,
                        'discount_percent' => $payload['discount_percent'] ?? null,
                        'cost_price' => $payload['cost_price'] ?? 0,
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
                // If any movements exist for this product, recompute from the ledger
                // so concurrent pushes from multiple devices converge correctly.
                $this->recomputeProductStock($uuid);
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
                $txExists = Transaction::where('id', $uuid)->exists();
                $txData = [
                    'business_id'    => $payload['business_id'] ?? null,
                    'location_id'    => $payload['location_id'] ?? null,
                    'user_id'        => $payload['user_id'] ?? null,
                    'customer_id'    => $payload['customer_id'] ?? null,
                    'subtotal'       => $payload['subtotal'] ?? 0,
                    'tax_total'      => $payload['tax_total'] ?? 0,
                    'discount_total' => $payload['discount_total'] ?? 0,
                    'total'          => $payload['total'] ?? 0,
                    'base_currency'  => $payload['base_currency'] ?? 'USD',
                    'status'         => $payload['status'] ?? 'completed',
                    'sale_number'    => $payload['sale_number'] ?? null,
                    'notes'          => $payload['notes'] ?? null,
                ];
                if (! $txExists && isset($payload['created_at'])) {
                    // Preserve the device's sale timestamp on first insert
                    $txData['created_at'] = $payload['created_at'];
                }
                Transaction::updateOrCreate(['id' => $uuid], $txData);
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
                        'business_id'     => $payload['business_id'] ?? null,
                        'location_id'     => $payload['location_id'] ?? null,
                        'to_location_id'  => $payload['to_location_id'] ?? null,
                        'product_id'      => $payload['product_id'] ?? null,
                        'type'            => $payload['type'] ?? 'adjustment',
                        'quantity_change' => $payload['quantity_change'] ?? 0,
                        'unit_cost'       => $payload['unit_cost'] ?? null,
                        'running_avg_cost' => $payload['running_avg_cost'] ?? null,
                        'reason'          => $payload['reason'] ?? null,
                        'reference_id'    => $payload['reference_id'] ?? null,
                        'attachment_path' => $payload['attachment_path'] ?? null,
                        'user_id'         => $payload['user_id'] ?? null,
                    ]
                );
                // Recompute the product's stock from the full movement ledger, per location.
                // This makes concurrent pushes from multiple devices converge.
                if (! empty($payload['product_id'])) {
                    $locationId = $payload['location_id'] ?? null;
                    if ($locationId) {
                        $this->recomputeLocationStock($payload['product_id'], $locationId);
                    } else {
                        $this->recomputeProductStock($payload['product_id']);
                    }
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
                        'business_id'           => $payload['business_id'] ?? null,
                        'receiving_location_id' => $payload['receiving_location_id'] ?? null,
                        'supplier_id'           => $payload['supplier_id'] ?? null,
                        'supplier_name'         => $payload['supplier_name'] ?? null,
                        'po_number'             => $payload['po_number'] ?? '',
                        'status'                => $payload['status'] ?? 'draft',
                        // total_ordered/total_received accepted from payload for initial insert.
                        // They will be overridden below if items exist (multi-device safe).
                        'total_ordered'         => $payload['total_ordered'] ?? 0,
                        'total_received'        => $payload['total_received'] ?? 0,
                        'notes'                 => $payload['notes'] ?? null,
                        'expected_date'         => $payload['expected_date'] ?? null,
                        'additional_costs_json' => $payload['additional_costs_json'] ?? null,
                        'created_by_user_id'    => $payload['created_by_user_id'] ?? null,
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

            case 'shifts':
                Shift::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                        'cashier_id'  => $payload['cashier_id'] ?? null,
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
                Expense::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id' => $payload['business_id'] ?? null,
                        'recorded_by_user_id' => $payload['recorded_by_user_id'] ?? null,
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
                StockTake::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id'         => $payload['business_id'] ?? null,
                        'location_id'         => $payload['location_id'] ?? null,
                        'title'               => $payload['title'] ?? '',
                        'status'              => $payload['status'] ?? 'draft',
                        'notes'               => $payload['notes'] ?? null,
                        'created_by_user_id'  => $payload['created_by_user_id'] ?? null,
                        'approved_by_user_id' => $payload['approved_by_user_id'] ?? null,
                        'approved_at'         => $payload['approved_at'] ?? null,
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
                        'business_id'              => $payload['business_id'] ?? null,
                        'user_id'                  => $payload['user_id'] ?? null,
                        'name'                     => $payload['name'] ?? '',
                        'job_title'                => $payload['job_title'] ?? null,
                        'department'               => $payload['department'] ?? null,
                        'phone'                    => $payload['phone'] ?? null,
                        'email'                    => $payload['email'] ?? null,
                        'national_id'              => $payload['national_id'] ?? null,
                        'address'                  => $payload['address'] ?? null,
                        'emergency_contact_name'   => $payload['emergency_contact_name'] ?? null,
                        'emergency_contact_phone'  => $payload['emergency_contact_phone'] ?? null,
                        'pay_type'                 => $payload['pay_type'] ?? 'monthly',
                        'salary_amount'            => $payload['salary_amount'] ?? 0,
                        'currency_code'            => $payload['currency_code'] ?? 'USD',
                        'hire_date'                => $payload['hire_date'] ?? null,
                        'termination_date'         => $payload['termination_date'] ?? null,
                        'notes'                    => $payload['notes'] ?? null,
                        'status'                   => $payload['status'] ?? 'active',
                        'photo_path'               => $payload['photo_path'] ?? null,
                    ]
                );
                break;

            case 'salary_payments':
                SalaryPayment::updateOrCreate(
                    ['id' => $uuid],
                    [
                        'business_id'    => $payload['business_id'] ?? null,
                        'employee_id'    => $payload['employee_id'] ?? null,
                        'period'         => $payload['period'] ?? '',
                        'amount'         => $payload['amount'] ?? 0,
                        'currency_code'  => $payload['currency_code'] ?? 'USD',
                        'base_equivalent' => $payload['base_equivalent'] ?? 0,
                        'exchange_rate'  => $payload['exchange_rate'] ?? 1,
                        'payment_method' => $payload['payment_method'] ?? 'cash',
                        'reference'      => $payload['reference'] ?? null,
                        'notes'          => $payload['notes'] ?? null,
                        'paid_by_user_id' => $payload['paid_by_user_id'] ?? null,
                        'paid_at'        => $payload['paid_at'] ?? now(),
                    ]
                );
                break;
        }
    }

    protected function syncUser(string $uuid, array $payload): void
    {
        $userData = [
            'business_id' => $payload['business_id'] ?? null,
            'name' => $payload['name'] ?? '',
            'email' => $payload['email'] ?? $uuid.'@pos.local',
            'password' => $payload['password'] ?? Hash::make('password'),
            'pin_hash' => $payload['pin_hash'] ?? null,
            'role' => $payload['role'] ?? 'cashier',
            'is_active' => $payload['is_active'] ?? true,
            'biometric_enabled' => $payload['biometric_enabled'] ?? false,
        ];

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
        Product::where('id', $productId)
            ->whereExists(function ($q) use ($productId) {
                $q->from('stock_movements')->where('product_id', $productId);
            })
            ->update(['stock_quantity' => $computed]);
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

        ProductStock::updateOrCreate(
            ['product_id' => $productId, 'location_id' => $locationId],
            ['quantity' => max(0, $computed)]
        );
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
            'businesses'      => Business::class,
            'locations'       => Location::class,
            'categories'      => Category::class,
            'tax_rates'       => TaxRate::class,
            'exchange_rates'  => ExchangeRate::class,
            'products'        => Product::class,
            'product_variants' => ProductVariant::class,
            'customers'       => Customer::class,
            'transactions'    => Transaction::class,
            'suppliers'       => Supplier::class,
            'purchase_orders' => PurchaseOrder::class,
            'stock_transfers' => StockTransfer::class,
            'coupons'         => Coupon::class,
            'shifts'          => Shift::class,
            'expenses'         => Expense::class,
            'stock_takes'      => StockTake::class,
            'employees'        => Employee::class,
            'salary_payments'  => SalaryPayment::class,
        ];

        if (isset($modelMap[$table])) {
            $modelMap[$table]::where('id', $uuid)->delete();
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
