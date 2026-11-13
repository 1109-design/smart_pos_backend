<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave requests, synced from the tills through PayrollSync (the till's
 * EmployeeLeaveRequests table). An approved annual request posts one
 * `taken` row to employee_leave_entries, linked back by leave_request_id
 * and keyed by a UUIDv5 of the request id — see LeaveRequestDecision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_leave_requests')) {
            Schema::create('employee_leave_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->uuid('employee_id');
                $table->string('employee_name');
                // annual | sick | maternity | paternity | compassionate | study | unpaid
                $table->string('leave_type');
                $table->timestamp('start_date');
                $table->timestamp('end_date');
                $table->boolean('half_day')->default(false);
                $table->decimal('days', 6, 2);
                $table->text('reason')->nullable();
                // pending | approved | rejected | cancelled
                $table->string('status')->default('pending');
                $table->uuid('requested_by_user_id');
                $table->uuid('decided_by_user_id')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_note')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->uuid('ledger_entry_id')->nullable();
                $table->timestamps();
                $table->index(['employee_id', 'start_date']);
                $table->index(['business_id', 'status']);
            });
        }

        if (! Schema::hasColumn('employee_leave_entries', 'leave_request_id')) {
            Schema::table('employee_leave_entries', function (Blueprint $table) {
                $table->uuid('leave_request_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_leave_entries', 'leave_request_id')) {
            Schema::table('employee_leave_entries', function (Blueprint $table) {
                $table->dropIndex(['leave_request_id']);
                $table->dropColumn('leave_request_id');
            });
        }
        Schema::dropIfExists('employee_leave_requests');
    }
};
