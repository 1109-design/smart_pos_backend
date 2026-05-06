<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add business_id to users so each user belongs to a business
        if (! Schema::hasColumn('users', 'business_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('business_id')->nullable()->after('id')->index();
            });
        }

        // Sync infrastructure
        if (! Schema::hasTable('businesses')) {
            Schema::create('businesses', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('address')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('tagline')->nullable();
                $table->string('tax_number')->nullable();
                $table->string('currency_code', 10)->default('USD');
                $table->string('base_currency_code', 10)->default('USD');
                $table->string('logo_path')->nullable();
                $table->string('subscription_tier')->default('starter');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sync_records')) {
            Schema::create('sync_records', function (Blueprint $table) {
                $table->id();
                $table->string('business_id')->index();
                $table->string('table_name');
                $table->uuid('record_uuid');
                $table->enum('operation', ['upsert', 'delete']);
                $table->json('payload')->nullable();
                $table->timestamp('synced_at');
                $table->unsignedBigInteger('device_id')->nullable();
                $table->boolean('conflict_resolved')->default(false);
                $table->text('sync_error')->nullable();
                $table->unsignedSmallInteger('retry_count')->default(0);
                $table->timestamps();

                $table->index(['business_id', 'table_name', 'record_uuid']);
                $table->index(['business_id', 'synced_at']);
            });
        }

        if (! Schema::hasTable('sync_cursors')) {
            Schema::create('sync_cursors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->string('table_name');
                $table->timestamp('last_pulled_at')->nullable();
                $table->timestamps();

                $table->unique(['device_id', 'table_name']);
            });
        }

        // Business tables
        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->uuid('parent_id')->nullable();
                $table->string('name');
                $table->string('color')->nullable();
                $table->string('icon')->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tax_rates')) {
            Schema::create('tax_rates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->string('name');
                $table->decimal('rate', 10, 4);
                $table->string('type')->default('exclusive');
                $table->boolean('is_compound')->default(false);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('currencies')) {
            Schema::create('currencies', function (Blueprint $table) {
                $table->string('code', 10)->primary();
                $table->string('name');
                $table->string('symbol', 10);
                $table->integer('decimal_places')->default(2);
                $table->boolean('is_base')->default(false);
                $table->boolean('is_enabled')->default(true);
            });
        }

        if (! Schema::hasTable('exchange_rates')) {
            Schema::create('exchange_rates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
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

        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->uuid('category_id')->nullable();
                $table->string('name');
                $table->string('sku')->nullable();
                $table->string('barcode')->nullable()->index();
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
            });
        }

        if (! Schema::hasTable('product_tax_rates')) {
            Schema::create('product_tax_rates', function (Blueprint $table) {
                $table->uuid('product_id');
                $table->uuid('tax_rate_id');
                $table->primary(['product_id', 'tax_rate_id']);
            });
        }

        if (! Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('product_id')->index();
                $table->string('name');
                $table->decimal('price_modifier', 15, 4)->default(0);
                $table->decimal('stock_quantity', 15, 4)->default(0);
                $table->string('barcode')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->string('name');
                $table->string('phone')->nullable()->index();
                $table->string('email')->nullable();
                $table->string('address')->nullable();
                $table->string('photo_path')->nullable();
                $table->decimal('loyalty_points', 15, 4)->default(0);
                $table->decimal('credit_balance', 15, 4)->default(0);
                $table->decimal('credit_limit', 15, 4)->default(0);
                $table->boolean('is_tax_exempt')->default(false);
                $table->string('group')->default('regular');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->uuid('user_id');
                $table->uuid('customer_id')->nullable();
                $table->decimal('subtotal', 15, 4);
                $table->decimal('tax_total', 15, 4)->default(0);
                $table->decimal('discount_total', 15, 4)->default(0);
                $table->decimal('total', 15, 4);
                $table->string('base_currency', 10);
                $table->string('status')->default('completed');
                $table->string('sale_number')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('transaction_items')) {
            Schema::create('transaction_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('transaction_id')->index();
                $table->uuid('product_id');
                $table->uuid('variant_id')->nullable();
                $table->string('product_name');
                $table->decimal('quantity', 15, 4);
                $table->decimal('unit_price', 15, 4);
                $table->decimal('discount', 15, 4)->default(0);
                $table->decimal('tax_amount', 15, 4)->default(0);
                $table->decimal('line_total', 15, 4);
                $table->text('notes')->nullable();
            });
        }

        if (! Schema::hasTable('transaction_taxes')) {
            Schema::create('transaction_taxes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('transaction_id')->index();
                $table->uuid('tax_rate_id')->nullable();
                $table->string('tax_name');
                $table->decimal('rate_snapshot', 10, 4);
                $table->decimal('taxable_amount', 15, 4);
                $table->decimal('tax_amount', 15, 4);
            });
        }

        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('transaction_id')->index();
                $table->string('method');
                $table->decimal('amount', 15, 4);
                $table->string('currency_code', 10);
                $table->decimal('exchange_rate_used', 20, 8)->default(1);
                $table->decimal('base_equivalent', 15, 4);
                $table->decimal('change_given', 15, 4)->default(0);
                $table->string('reference')->nullable();
            });
        }

        if (! Schema::hasTable('stock_movements')) {
            Schema::create('stock_movements', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->uuid('product_id');
                $table->string('type');
                $table->decimal('quantity_change', 15, 4);
                $table->decimal('unit_cost', 15, 4)->nullable();
                $table->decimal('running_avg_cost', 15, 4)->nullable();
                $table->text('reason')->nullable();
                $table->uuid('reference_id')->nullable()->index();
                $table->string('attachment_path')->nullable();
                $table->uuid('user_id');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('loyalty_transactions')) {
            Schema::create('loyalty_transactions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('customer_id')->index();
                $table->uuid('transaction_id')->nullable();
                $table->decimal('points', 15, 4);
                $table->string('type');
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('credit_transactions')) {
            Schema::create('credit_transactions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('customer_id')->index();
                $table->uuid('transaction_id')->nullable();
                $table->decimal('amount', 15, 4);
                $table->string('type');
                $table->string('method')->nullable();
                $table->string('reference')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
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
            });
        }

        if (! Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->uuid('supplier_id')->nullable();
                $table->string('supplier_name')->nullable();
                $table->string('po_number')->index();
                $table->string('status')->default('draft');
                $table->decimal('total_ordered', 15, 4)->default(0);
                $table->decimal('total_received', 15, 4)->default(0);
                $table->text('notes')->nullable();
                $table->timestamp('expected_date')->nullable();
                $table->json('additional_costs_json')->nullable();
                $table->uuid('created_by_user_id');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchase_order_items')) {
            Schema::create('purchase_order_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('purchase_order_id')->index();
                $table->uuid('product_id');
                $table->string('product_name');
                $table->decimal('ordered_qty', 15, 4);
                $table->decimal('received_qty', 15, 4)->default(0);
                $table->decimal('unit_cost', 15, 4);
                $table->decimal('received_unit_cost', 15, 4)->nullable();
            });
        }

        if (! Schema::hasTable('coupons')) {
            Schema::create('coupons', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
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
            });
        }

        if (! Schema::hasTable('shifts')) {
            Schema::create('shifts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
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
            });
        }

        if (! Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
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
            });
        }

        if (! Schema::hasTable('stock_takes')) {
            Schema::create('stock_takes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->string('title');
                $table->string('status')->default('draft');
                $table->text('notes')->nullable();
                $table->uuid('created_by_user_id');
                $table->uuid('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stock_take_items')) {
            Schema::create('stock_take_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('stock_take_id')->index();
                $table->uuid('product_id');
                $table->string('product_name');
                $table->decimal('system_qty', 15, 4);
                $table->decimal('counted_qty', 15, 4)->nullable();
                $table->text('notes')->nullable();
            });
        }

        if (! Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->string('business_id');
                $table->string('role');
                $table->json('permissions_json');
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->primary(['business_id', 'role']);
            });
        }

        if (! Schema::hasTable('po_audit_logs')) {
            Schema::create('po_audit_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('po_id')->index();
                $table->uuid('user_id');
                $table->string('user_name');
                $table->string('action');
                $table->text('note')->nullable();
                $table->json('snapshot_json')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'po_audit_logs', 'role_permissions', 'stock_take_items', 'stock_takes',
            'expenses', 'shifts', 'coupons', 'purchase_order_items', 'purchase_orders',
            'suppliers', 'credit_transactions', 'loyalty_transactions', 'stock_movements',
            'payments', 'transaction_taxes', 'transaction_items', 'transactions',
            'customers', 'product_variants', 'product_tax_rates', 'products',
            'exchange_rates', 'currencies', 'tax_rates', 'categories',
            'sync_cursors', 'sync_records', 'businesses',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('business_id');
        });
    }
};
