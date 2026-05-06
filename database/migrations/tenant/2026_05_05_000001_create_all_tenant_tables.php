<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('categories')) {
            Schema::connection('tenant')->create('categories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('parent_id')->nullable();
                $table->string('name');
                $table->string('color')->nullable();
                $table->string('icon')->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('business_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('tax_rates')) {
            Schema::connection('tenant')->create('tax_rates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->string('name');
                $table->decimal('rate', 10, 4);
                $table->string('type')->default('exclusive');
                $table->boolean('is_compound')->default(false);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('business_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('currencies')) {
            Schema::connection('tenant')->create('currencies', function (Blueprint $table) {
                $table->string('code', 10)->primary();
                $table->string('name');
                $table->string('symbol', 10);
                $table->integer('decimal_places')->default(2);
                $table->boolean('is_base')->default(false);
                $table->boolean('is_enabled')->default(true);
            });
        }

        if (! Schema::connection('tenant')->hasTable('exchange_rates')) {
            Schema::connection('tenant')->create('exchange_rates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('from_currency', 10);
                $table->string('to_currency', 10);
                $table->decimal('rate', 20, 8);
                $table->string('source')->default('manual');
                $table->uuid('set_by_user_id')->nullable();
                $table->boolean('locked')->default(false);
                $table->timestamp('valid_from');
                $table->timestamp('valid_until')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::connection('tenant')->hasTable('products')) {
            Schema::connection('tenant')->create('products', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('category_id')->nullable();
                $table->string('name');
                $table->string('sku')->nullable();
                $table->string('barcode')->nullable();
                $table->decimal('price', 15, 4);
                $table->decimal('min_price', 15, 4)->nullable();
                $table->decimal('discount_percent', 5, 2)->nullable();
                $table->decimal('cost_price', 15, 4)->default(0);
                $table->string('unit')->default('piece');
                $table->boolean('track_stock')->default(true);
                $table->decimal('stock_quantity', 15, 4)->default(0);
                $table->decimal('low_stock_threshold', 15, 4)->default(5);
                $table->string('image_path')->nullable();
                $table->timestamp('expiry_date')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('business_id');
                $table->index('barcode');
                $table->index('sku');
            });
        }

        if (! Schema::connection('tenant')->hasTable('product_tax_rates')) {
            Schema::connection('tenant')->create('product_tax_rates', function (Blueprint $table) {
                $table->uuid('product_id');
                $table->uuid('tax_rate_id');
                $table->primary(['product_id', 'tax_rate_id']);
            });
        }

        if (! Schema::connection('tenant')->hasTable('product_variants')) {
            Schema::connection('tenant')->create('product_variants', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('product_id');
                $table->string('name');
                $table->decimal('price_modifier', 15, 4)->default(0);
                $table->decimal('stock_quantity', 15, 4)->default(0);
                $table->string('barcode')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('product_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('customers')) {
            Schema::connection('tenant')->create('customers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->string('name');
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('address')->nullable();
                $table->string('photo_path')->nullable();
                $table->decimal('loyalty_points', 15, 4)->default(0);
                $table->decimal('credit_balance', 15, 4)->default(0);
                $table->decimal('credit_limit', 15, 4)->default(0);
                $table->boolean('is_tax_exempt')->default(false);
                $table->string('group')->default('regular');
                $table->timestamps();

                $table->index('business_id');
                $table->index('phone');
            });
        }

        if (! Schema::connection('tenant')->hasTable('transactions')) {
            Schema::connection('tenant')->create('transactions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('user_id');
                $table->uuid('customer_id')->nullable();
                $table->decimal('subtotal', 15, 4);
                $table->decimal('tax_total', 15, 4)->default(0);
                $table->decimal('discount_total', 15, 4)->default(0);
                $table->decimal('total', 15, 4);
                $table->string('base_currency', 10);
                $table->string('status')->default('completed');
                $table->string('sale_number')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('business_id');
                $table->index('sale_number');
                $table->index(['business_id', 'created_at']);
            });
        }

        if (! Schema::connection('tenant')->hasTable('transaction_items')) {
            Schema::connection('tenant')->create('transaction_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('transaction_id');
                $table->uuid('product_id');
                $table->uuid('variant_id')->nullable();
                $table->string('product_name');
                $table->decimal('quantity', 15, 4);
                $table->decimal('unit_price', 15, 4);
                $table->decimal('discount', 15, 4)->default(0);
                $table->decimal('tax_amount', 15, 4)->default(0);
                $table->decimal('line_total', 15, 4);
                $table->text('notes')->nullable();

                $table->index('transaction_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('transaction_taxes')) {
            Schema::connection('tenant')->create('transaction_taxes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('transaction_id');
                $table->uuid('tax_rate_id')->nullable();
                $table->string('tax_name');
                $table->decimal('rate_snapshot', 10, 4);
                $table->decimal('taxable_amount', 15, 4);
                $table->decimal('tax_amount', 15, 4);

                $table->index('transaction_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('payments')) {
            Schema::connection('tenant')->create('payments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('transaction_id');
                $table->string('method');
                $table->decimal('amount', 15, 4);
                $table->string('currency_code', 10);
                $table->decimal('exchange_rate_used', 20, 8)->default(1);
                $table->decimal('base_equivalent', 15, 4);
                $table->decimal('change_given', 15, 4)->default(0);
                $table->string('reference')->nullable();

                $table->index('transaction_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('stock_movements')) {
            Schema::connection('tenant')->create('stock_movements', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('product_id');
                $table->string('type');
                $table->decimal('quantity_change', 15, 4);
                $table->decimal('unit_cost', 15, 4)->nullable();
                $table->decimal('running_avg_cost', 15, 4)->nullable();
                $table->text('reason')->nullable();
                $table->uuid('reference_id')->nullable();
                $table->string('attachment_path')->nullable();
                $table->uuid('user_id');
                $table->timestamps();

                $table->index(['business_id', 'product_id']);
                $table->index('reference_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('loyalty_transactions')) {
            Schema::connection('tenant')->create('loyalty_transactions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('customer_id');
                $table->uuid('transaction_id')->nullable();
                $table->decimal('points', 15, 4);
                $table->string('type');
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index('customer_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('credit_transactions')) {
            Schema::connection('tenant')->create('credit_transactions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('customer_id');
                $table->uuid('transaction_id')->nullable();
                $table->decimal('amount', 15, 4);
                $table->string('type');
                $table->string('method')->nullable();
                $table->string('reference')->nullable();
                $table->timestamps();

                $table->index('customer_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('suppliers')) {
            Schema::connection('tenant')->create('suppliers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->string('name');
                $table->string('contact_name')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('address')->nullable();
                $table->string('website')->nullable();
                $table->text('notes')->nullable();
                $table->string('tax_number')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('business_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('purchase_orders')) {
            Schema::connection('tenant')->create('purchase_orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('supplier_id')->nullable();
                $table->string('supplier_name')->nullable();
                $table->string('po_number');
                $table->string('status')->default('draft');
                $table->decimal('total_ordered', 15, 4)->default(0);
                $table->decimal('total_received', 15, 4)->default(0);
                $table->text('notes')->nullable();
                $table->timestamp('expected_date')->nullable();
                $table->json('additional_costs_json')->nullable();
                $table->uuid('created_by_user_id');
                $table->timestamps();

                $table->index('business_id');
                $table->index('po_number');
            });
        }

        if (! Schema::connection('tenant')->hasTable('purchase_order_items')) {
            Schema::connection('tenant')->create('purchase_order_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('purchase_order_id');
                $table->uuid('product_id');
                $table->string('product_name');
                $table->decimal('ordered_qty', 15, 4);
                $table->decimal('received_qty', 15, 4)->default(0);
                $table->decimal('unit_cost', 15, 4);
                $table->decimal('received_unit_cost', 15, 4)->nullable();

                $table->index('purchase_order_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('coupons')) {
            Schema::connection('tenant')->create('coupons', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->string('code');
                $table->text('description')->nullable();
                $table->string('type')->default('percent');
                $table->decimal('value', 15, 4);
                $table->decimal('min_order_amount', 15, 4)->default(0);
                $table->integer('max_uses')->nullable();
                $table->integer('uses_count')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'code']);
            });
        }

        if (! Schema::connection('tenant')->hasTable('shifts')) {
            Schema::connection('tenant')->create('shifts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('cashier_id');
                $table->timestamp('opened_at');
                $table->timestamp('closed_at')->nullable();
                $table->string('status')->default('open');
                $table->decimal('opening_float', 15, 4)->default(0);
                $table->decimal('expected_cash', 15, 4)->nullable();
                $table->decimal('counted_cash', 15, 4)->nullable();
                $table->decimal('variance', 15, 4)->nullable();
                $table->decimal('total_sales', 15, 4)->nullable();
                $table->decimal('cash_sales', 15, 4)->nullable();
                $table->decimal('card_sales', 15, 4)->nullable();
                $table->decimal('mobile_money_sales', 15, 4)->nullable();
                $table->decimal('credit_sales', 15, 4)->nullable();
                $table->decimal('total_refunds', 15, 4)->nullable();
                $table->decimal('total_discounts', 15, 4)->nullable();
                $table->integer('transaction_count')->nullable();
                $table->json('opening_float_json')->nullable();
                $table->json('counted_cash_json')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status']);
                $table->index('cashier_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('expenses')) {
            Schema::connection('tenant')->create('expenses', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->uuid('recorded_by_user_id');
                $table->string('category');
                $table->text('description')->nullable();
                $table->decimal('amount', 15, 4);
                $table->string('currency_code', 10);
                $table->decimal('base_equivalent', 15, 4);
                $table->decimal('exchange_rate', 20, 8)->default(1);
                $table->string('payment_method')->default('cash');
                $table->string('mobile_provider')->nullable();
                $table->string('payment_reference')->nullable();
                $table->string('receipt_path')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('expense_date');
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'expense_date']);
            });
        }

        if (! Schema::connection('tenant')->hasTable('stock_takes')) {
            Schema::connection('tenant')->create('stock_takes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('business_id');
                $table->string('title');
                $table->string('status')->default('draft');
                $table->text('notes')->nullable();
                $table->uuid('created_by_user_id');
                $table->uuid('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->index('business_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('stock_take_items')) {
            Schema::connection('tenant')->create('stock_take_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('stock_take_id');
                $table->uuid('product_id');
                $table->string('product_name');
                $table->decimal('system_qty', 15, 4);
                $table->decimal('counted_qty', 15, 4)->nullable();
                $table->text('notes')->nullable();

                $table->index('stock_take_id');
            });
        }

        if (! Schema::connection('tenant')->hasTable('role_permissions')) {
            Schema::connection('tenant')->create('role_permissions', function (Blueprint $table) {
                $table->uuid('business_id');
                $table->string('role');
                $table->json('permissions_json');
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->primary(['business_id', 'role']);
            });
        }

        if (! Schema::connection('tenant')->hasTable('po_audit_logs')) {
            Schema::connection('tenant')->create('po_audit_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('po_id');
                $table->uuid('user_id');
                $table->string('user_name');
                $table->string('action');
                $table->text('note')->nullable();
                $table->json('snapshot_json')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('po_id');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('po_audit_logs');
        Schema::connection('tenant')->dropIfExists('role_permissions');
        Schema::connection('tenant')->dropIfExists('stock_take_items');
        Schema::connection('tenant')->dropIfExists('stock_takes');
        Schema::connection('tenant')->dropIfExists('expenses');
        Schema::connection('tenant')->dropIfExists('shifts');
        Schema::connection('tenant')->dropIfExists('coupons');
        Schema::connection('tenant')->dropIfExists('purchase_order_items');
        Schema::connection('tenant')->dropIfExists('purchase_orders');
        Schema::connection('tenant')->dropIfExists('suppliers');
        Schema::connection('tenant')->dropIfExists('credit_transactions');
        Schema::connection('tenant')->dropIfExists('loyalty_transactions');
        Schema::connection('tenant')->dropIfExists('stock_movements');
        Schema::connection('tenant')->dropIfExists('payments');
        Schema::connection('tenant')->dropIfExists('transaction_taxes');
        Schema::connection('tenant')->dropIfExists('transaction_items');
        Schema::connection('tenant')->dropIfExists('transactions');
        Schema::connection('tenant')->dropIfExists('customers');
        Schema::connection('tenant')->dropIfExists('product_variants');
        Schema::connection('tenant')->dropIfExists('product_tax_rates');
        Schema::connection('tenant')->dropIfExists('products');
        Schema::connection('tenant')->dropIfExists('exchange_rates');
        Schema::connection('tenant')->dropIfExists('currencies');
        Schema::connection('tenant')->dropIfExists('tax_rates');
        Schema::connection('tenant')->dropIfExists('categories');
    }
};
