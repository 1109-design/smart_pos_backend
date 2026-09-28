<?php

namespace App\Exceptions;

/**
 * Thrown by SyncProcessor::assertOwnership() when a child record's incoming
 * parent FK doesn't resolve to any existing row yet — i.e. the parent simply
 * hasn't arrived on the server, not that it belongs to another business.
 *
 * Distinct from the plain \RuntimeException thrown for a genuine ownership
 * mismatch (parent exists but belongs to a different tenant) so
 * SyncController::push() can tell "defer and retry later" apart from "reject,
 * this is a security violation" — see resolvePendingRecords().
 */
class MissingParentRecordException extends \RuntimeException
{
    public function __construct(string $table)
    {
        parent::__construct("{$table}: referenced parent does not exist yet.");
    }
}
