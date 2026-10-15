<?php

namespace App\Exceptions;

/**
 * A device-posted journal the server refuses. Carries a machine-readable
 * reason code the device maps to a fix in its sync exceptions queue:
 * unbalanced | no_lines | invalid_line | unknown_account | inactive_account
 * | missing_party | closed_period | duplicate | duplicate_number | conflict
 * | device_mismatch | not_reversible | forbidden.
 */
class JournalRejectedException extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
