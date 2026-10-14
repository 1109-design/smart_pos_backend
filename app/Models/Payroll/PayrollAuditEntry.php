<?php

namespace App\Models\Payroll;

/** Who changed what in payroll, as recorded on the till. Append-only. */
class PayrollAuditEntry extends PayrollModel
{
    protected $table = 'payroll_audit_log';
}
