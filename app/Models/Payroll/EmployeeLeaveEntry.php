<?php

namespace App\Models\Payroll;

/** One movement in an employee's annual leave balance. */
class EmployeeLeaveEntry extends PayrollModel
{
    protected $table = 'employee_leave_entries';
}
