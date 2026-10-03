<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll, mirrored from the till (Flutter schema v106, the "Payroll"
 * section of tables.dart, plus the raw-SQL employee_leave_entries ledger).
 * The tills calculate everything; the server stores what they sync, guards
 * approved runs against edits (see PayrollSync) and holds the one lock per
 * business per month that stops two offline devices approving the same
 * payroll (payroll_period_locks, see PayrollPeriodLockController).
 *
 * Money columns are USD unless the name ends in _zwg; rates are percents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->string('id')->primary(); // = business_id
            $table->string('business_id')->index();
            $table->string('tax_method')->default('per_portion');
            $table->string('zig_basis')->default('gross');
            $table->string('zig_currency_code')->default('ZWG');
            $table->boolean('self_service_payslips')->default(false);
            $table->decimal('overtime_multiplier', 8, 4)->default(1.5);
            $table->decimal('working_days_per_month', 8, 2)->nullable();
            $table->decimal('leave_accrual_days_per_month', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pay_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('code');
            $table->string('name');
            $table->string('kind'); // earning | deduction | employer_contribution
            $table->boolean('taxable')->default(true);
            $table->boolean('nssa_insurable')->default(true);
            $table->string('calc_type')->default('fixed');
            $table->string('tax_treatment')->default('none');
            $table->string('gl_role')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('employee_pay_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('employee_id')->index();
            $table->string('pay_basis')->default('salaried');
            $table->string('pay_frequency')->default('monthly');
            $table->decimal('basic_usd', 15, 4)->default(0);
            $table->decimal('hourly_rate_usd', 15, 4)->default(0);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('nssa_number')->nullable();
            $table->boolean('is_elderly')->default(false);
            $table->boolean('is_disabled')->default(false);
            $table->string('bank_name')->nullable();
            $table->string('bank_account_no')->nullable();
            $table->string('mobile_money_no')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_pay_splits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('employee_id')->index();
            $table->string('mode')->default('all_usd');
            $table->string('zig_basis')->default('percent');
            $table->decimal('zig_value', 15, 4)->default(0);
            $table->timestamp('effective_from');
            $table->timestamps();
        });

        Schema::create('employee_recurring_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('employee_id')->index();
            $table->uuid('component_id');
            $table->decimal('amount_usd', 15, 4)->nullable();
            $table->decimal('percent', 8, 4)->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_loans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('employee_id')->index();
            $table->string('kind')->default('loan');
            $table->decimal('principal_usd', 15, 4);
            $table->decimal('installment_usd', 15, 4);
            $table->timestamp('issued_at');
            $table->string('payment_method')->default('cash');
            $table->uuid('bank_account_id')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->uuid('created_by_user_id');
            $table->timestamps();
        });

        Schema::create('tax_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('name');
            $table->string('currency_code');
            $table->string('period_type')->default('monthly');
            $table->timestamp('effective_from');
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('tax_table_bands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('tax_table_id')->index();
            $table->decimal('lower_bound', 18, 4);
            $table->decimal('upper_bound', 18, 4)->nullable();
            $table->decimal('rate_percent', 8, 4);
            $table->decimal('deduct', 18, 4)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('statutory_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->timestamp('effective_from');
            $table->string('status')->default('draft');
            foreach ([
                'aids_levy_percent', 'nssa_employee_percent', 'nssa_employer_percent',
                'zimdef_percent', 'wcif_percent', 'nec_employer_percent',
                'nec_employee_percent', 'medical_aid_credit_percent',
            ] as $col) {
                $table->decimal($col, 8, 4)->default(0);
            }
            $table->decimal('nssa_ceiling_usd', 15, 4)->nullable();
            $table->decimal('elderly_credit_usd', 15, 4)->default(0);
            $table->decimal('disabled_credit_usd', 15, 4)->default(0);
            $table->decimal('pension_cap_usd', 15, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('pay_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('run_number');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->dateTime('pay_date');
            $table->string('status')->default('draft');
            $table->string('kind')->default('regular');
            $table->uuid('reverses_run_id')->nullable();
            $table->decimal('zig_rate', 18, 6)->nullable();
            $table->boolean('zig_rate_overridden')->default(false);
            $table->string('tax_method')->nullable();
            $table->string('zig_basis')->nullable();
            $table->uuid('tax_table_usd_id')->nullable();
            $table->uuid('tax_table_zwg_id')->nullable();
            $table->uuid('statutory_settings_id')->nullable();
            foreach ([
                'total_gross_usd', 'total_net_usd', 'total_net_zwg',
                'total_paye_usd', 'total_paye_zwg', 'total_employer_cost_usd',
            ] as $col) {
                $table->decimal($col, 18, 4)->default(0);
            }
            $table->integer('headcount')->default(0);
            $table->text('notes')->nullable();
            $table->uuid('created_by_user_id');
            $table->timestamp('calculated_at')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'period_year', 'period_month']);
        });

        Schema::create('pay_run_employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('pay_run_id')->index();
            $table->uuid('employee_id')->index();
            $table->string('employee_name');
            $table->uuid('pay_profile_id')->nullable();
            foreach (['hours', 'overtime_hours', 'unpaid_leave_days'] as $col) {
                $table->decimal($col, 10, 2)->default(0);
            }
            $table->decimal('pro_rata_factor', 8, 4)->default(1);
            $table->decimal('zig_share', 8, 6)->default(0);
            foreach ([
                'gross_usd', 'taxable_usd', 'nssa_insurable_usd', 'paye_usd',
                'paye_zwg', 'aids_levy_usd', 'aids_levy_zwg', 'nssa_employee_usd',
                'other_deductions_usd', 'net_total_usd', 'net_pay_usd',
                'net_pay_zwg', 'nssa_employer_usd', 'zimdef_usd', 'wcif_usd',
                'nec_employer_usd',
            ] as $col) {
                $table->decimal($col, 18, 4)->default(0);
            }
            $table->timestamp('paid_usd_at')->nullable();
            $table->string('paid_usd_method')->nullable();
            $table->uuid('paid_usd_bank_account_id')->nullable();
            $table->timestamp('paid_zwg_at')->nullable();
            $table->string('paid_zwg_method')->nullable();
            $table->uuid('paid_zwg_bank_account_id')->nullable();
            $table->uuid('paid_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('pay_run_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('pay_run_id')->index();
            $table->uuid('pay_run_employee_id')->index();
            $table->uuid('component_id')->nullable();
            $table->string('component_code');
            $table->string('name');
            $table->string('kind');
            $table->string('source');
            $table->decimal('quantity', 15, 4)->nullable();
            $table->decimal('rate', 18, 4)->nullable();
            $table->string('currency_code')->default('USD');
            $table->decimal('amount', 18, 4);
            $table->decimal('amount_usd', 18, 4);
            $table->uuid('loan_id')->nullable()->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('statutory_remittances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('kind');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('currency_code');
            $table->decimal('amount', 18, 4);
            $table->decimal('amount_usd', 18, 4);
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->string('payment_method')->default('bank_transfer');
            $table->uuid('bank_account_id')->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('paid_at');
            $table->uuid('paid_by_user_id');
            $table->timestamps();
        });

        Schema::create('employee_leave_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('employee_id')->index();
            $table->string('kind'); // accrual | taken | payout | adjustment
            $table->decimal('days', 10, 2);
            $table->timestamp('entry_date');
            $table->uuid('pay_run_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_period_locks', function (Blueprint $table) {
            $table->id();
            $table->string('business_id');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->uuid('pay_run_id');
            $table->uuid('locked_by_user_id');
            $table->timestamps();
            $table->unique(['business_id', 'period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        foreach ([
            'payroll_period_locks', 'employee_leave_entries', 'statutory_remittances',
            'pay_run_lines', 'pay_run_employees', 'pay_runs', 'statutory_settings',
            'tax_table_bands', 'tax_tables', 'employee_loans',
            'employee_recurring_components', 'employee_pay_splits',
            'employee_pay_profiles', 'pay_components', 'payroll_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
