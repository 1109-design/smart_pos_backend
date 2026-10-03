<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. payment_method_configs table
        if (! Schema::hasTable('payment_method_configs')) {
            Schema::create('payment_method_configs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->string('method'); // cash, card, mobile_money, bank_transfer, credit, layby, cheque, eft, other
                $table->string('currency_code', 10);
                $table->uuid('payment_account_id')->nullable();
                $table->string('provider')->nullable();
                $table->uuid('gl_account_id')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->boolean('require_reference')->default(false);
                $table->boolean('allows_change')->default(false);
                $table->string('display_label');
                $table->timestamps();

                $table->index(['business_id', 'method', 'currency_code'], 'idx_pmc_biz_method_curr');
            });
        }

        // 2. Add provider and gl_account_id to payments
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (! Schema::hasColumn('payments', 'provider')) {
                    $table->string('provider')->nullable()->after('reference');
                }
                if (! Schema::hasColumn('payments', 'gl_account_id')) {
                    $table->uuid('gl_account_id')->nullable()->after('provider');
                }
            });
        }

        // 3. Add currency_code, exchange_rate, base_amount to credit_transactions
        if (Schema::hasTable('credit_transactions')) {
            Schema::table('credit_transactions', function (Blueprint $table) {
                if (! Schema::hasColumn('credit_transactions', 'currency_code')) {
                    $table->string('currency_code', 10)->default('USD')->after('created_by_user_id');
                }
                if (! Schema::hasColumn('credit_transactions', 'exchange_rate')) {
                    $table->decimal('exchange_rate', 15, 6)->default(1.0)->after('currency_code');
                }
                if (! Schema::hasColumn('credit_transactions', 'base_amount')) {
                    $table->decimal('base_amount', 15, 4)->nullable()->after('exchange_rate');
                }
            });
        }

        // 4. Add provider, gl_account_id to supplier_payments
        if (Schema::hasTable('supplier_payments')) {
            Schema::table('supplier_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('supplier_payments', 'provider')) {
                    $table->string('provider')->nullable()->after('reference');
                }
                if (! Schema::hasColumn('supplier_payments', 'gl_account_id')) {
                    $table->uuid('gl_account_id')->nullable()->after('provider');
                }
            });
        }

        // 5. Add provider, gl_account_id to customer_receipts
        if (Schema::hasTable('customer_receipts')) {
            Schema::table('customer_receipts', function (Blueprint $table) {
                if (! Schema::hasColumn('customer_receipts', 'provider')) {
                    $table->string('provider')->nullable()->after('reference');
                }
                if (! Schema::hasColumn('customer_receipts', 'gl_account_id')) {
                    $table->uuid('gl_account_id')->nullable()->after('provider');
                }
            });
        }

        // 6. Add bank_transfer_sales, other_sales to shifts
        if (Schema::hasTable('shifts')) {
            Schema::table('shifts', function (Blueprint $table) {
                if (! Schema::hasColumn('shifts', 'bank_transfer_sales')) {
                    $table->decimal('bank_transfer_sales', 15, 4)->nullable()->after('credit_sales');
                }
                if (! Schema::hasColumn('shifts', 'other_sales')) {
                    $table->decimal('other_sales', 15, 4)->nullable()->after('bank_transfer_sales');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_method_configs');

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn(['provider', 'gl_account_id']);
            });
        }

        if (Schema::hasTable('credit_transactions')) {
            Schema::table('credit_transactions', function (Blueprint $table) {
                $table->dropColumn(['currency_code', 'exchange_rate', 'base_amount']);
            });
        }

        if (Schema::hasTable('supplier_payments')) {
            Schema::table('supplier_payments', function (Blueprint $table) {
                $table->dropColumn(['provider', 'gl_account_id']);
            });
        }

        if (Schema::hasTable('customer_receipts')) {
            Schema::table('customer_receipts', function (Blueprint $table) {
                $table->dropColumn(['provider', 'gl_account_id']);
            });
        }

        if (Schema::hasTable('shifts')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->dropColumn(['bank_transfer_sales', 'other_sales']);
            });
        }
    }
};
