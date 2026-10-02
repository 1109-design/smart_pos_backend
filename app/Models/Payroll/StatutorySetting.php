<?php

namespace App\Models\Payroll;

/** Business-entered statutory rates, versioned by effective date. */
class StatutorySetting extends PayrollModel
{
    protected $table = 'statutory_settings';
}
