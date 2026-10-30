<?php

namespace App\Models\Payroll;

/** One monthly pay run. */
class PayRun extends PayrollModel
{
    protected $table = 'pay_runs';

    protected $casts = [
        'totals_checked_at' => 'datetime',
        'totals_mismatch' => 'array',
    ];
}
