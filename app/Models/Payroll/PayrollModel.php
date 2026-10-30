<?php

namespace App\Models\Payroll;

use Illuminate\Database\Eloquent\Model;

/**
 * Base for the payroll tables synced from the tills (see the
 * create_payroll_tables migration). Ids are the tills' UUIDs; rows are
 * written only by PayrollSync, which filters payloads to real columns.
 */
abstract class PayrollModel extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
