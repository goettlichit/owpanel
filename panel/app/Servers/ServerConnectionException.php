<?php

namespace App\Servers;

use RuntimeException;

/**
 * The connection to a server failed before or while a script ran. The error
 * code is shown translated in the interface; the message is for the log and
 * never contains secrets.
 */
class ServerConnectionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
