<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->uuid('user_id')->nullable();
                $table->string('name');
                $table->string('job_title')->nullable();
                $table->string('department')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('national_id')->nullable();
                $table->text('address')->nullable();
                $table->string('emergency_contact_name')->nullable();
                $table->string('emergency_contact_phone')->nullable();
                $table->string('pay_type')->default('monthly'); // monthly | weekly | hourly | commission
                $table->decimal('salary_amount', 15, 4)->default(0);
                $table->string('currency_code', 10)->default('USD');
                $table->timestamp('hire_date')->nullable();
                $table->timestamp('termination_date')->nullable();
                $table->text('notes')->nullable();
                $table->string('status')->default('active'); // active | on_leave | terminated
                $table->string('photo_path')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status']);
            });
        }

        if (! Schema::hasTable('salary_payments')) {
            Schema::create('salary_payments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->uuid('employee_id');
                $table->string('period', 7); // e.g. "2026-05"
                $table->decimal('amount', 15, 4);
                $table->string('currency_code', 10);
                $table->decimal('base_equivalent', 15, 4);
                $table->decimal('exchange_rate', 20, 8)->default(1);
                $table->string('payment_method')->default('cash'); // cash | bank_transfer | mobile_money | cheque
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('paid_by_user_id');
                $table->timestamp('paid_at');
                $table->timestamps();

                $table->index(['business_id', 'employee_id', 'period']);
                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_payments');
        Schema::dropIfExists('employees');
    }
};
