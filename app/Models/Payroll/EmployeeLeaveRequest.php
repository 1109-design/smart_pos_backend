<?php

namespace App\Models\Payroll;

/**
 * An employee's leave request, decided through the Approvals inbox. See
 * App\Services\Payroll\LeaveRequestDecision.
 */
class EmployeeLeaveRequest extends PayrollModel
{
    protected $table = 'employee_leave_requests';

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'decided_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'half_day' => 'boolean',
        'days' => 'float',
    ];
}
