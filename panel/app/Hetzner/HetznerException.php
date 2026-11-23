<?php

namespace App\Hetzner;

use RuntimeException;

/**
 * An error of the Hetzner Cloud API with a short code for the interface:
 * not_configured, unauthorized, forbidden, not_found, rate_limited,
 * connection_failed, api_error, action_failed or timeout.
 */
class HetznerException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
