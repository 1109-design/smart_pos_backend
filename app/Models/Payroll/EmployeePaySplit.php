<?php

namespace App\Models\Payroll;

/** How an employee's net pay is paid out between USD and ZiG. */
class EmployeePaySplit extends PayrollModel
{
    protected $table = 'employee_pay_splits';
}
