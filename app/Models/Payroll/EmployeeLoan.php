<?php

namespace App\Models\Payroll;

/** A staff loan or salary advance repaid through pay runs. */
class EmployeeLoan extends PayrollModel
{
    protected $table = 'employee_loans';
}
