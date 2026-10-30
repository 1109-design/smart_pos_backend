<?php

namespace App\Models\Payroll;

use Illuminate\Database\Eloquent\Model;

/** The one pay run allowed to be approved for a business's month. */
class PayrollPeriodLock extends Model
{
    protected $table = 'payroll_period_locks';

    protected $guarded = [];
}
