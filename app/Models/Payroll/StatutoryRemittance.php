<?php

namespace App\Models\Payroll;

/** A payment of withheld or employer statutory amounts. */
class StatutoryRemittance extends PayrollModel
{
    protected $table = 'statutory_remittances';
}
