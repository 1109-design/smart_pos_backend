<?php

namespace App\Exceptions;

/**
 * The server no longer creates, posts or reverses journals: the device
 * that performs a transaction posts its journal and the server only
 * checks and stores it (JournalBundleIngestor). Thrown by every server
 * posting entry point so a BackOffice action that used to post fails with
 * a clear message instead of quietly booking on the server.
 */
class ServerPostingRetiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Journals are now posted on a SmartPOS device — the server no longer posts. Record this in the app instead.');
    }
}
