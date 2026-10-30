<?php

namespace App\Models\Payroll;

/** A business-entered PAYE table for one currency. */
class TaxTable extends PayrollModel
{
    protected $table = 'tax_tables';
}
