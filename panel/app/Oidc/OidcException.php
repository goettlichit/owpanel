<?php

namespace App\Oidc;

use RuntimeException;

/**
 * An error of the OIDC login (E-113) with a short code for the interface:
 * not_configured, invalid_issuer, connection_failed, discovery_failed,
 * issuer_mismatch, insecure_endpoint, no_code_flow, no_pkce,
 * no_signing_alg, jwks_failed, token_failed or invalid_token. The message
 * holds details for the log only; it never reaches the user.
 */
class OidcException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
