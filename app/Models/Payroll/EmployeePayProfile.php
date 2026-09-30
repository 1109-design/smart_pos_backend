<?php

namespace App\Models\Payroll;

/** An employee's contract pay, one row per effective period. */
class EmployeePayProfile extends PayrollModel
{
    protected $table = 'employee_pay_profiles';
}
