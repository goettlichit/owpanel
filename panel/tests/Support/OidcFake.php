<?php

namespace Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;

/**
 * An OpenID Connect provider for tests: discovery document, key set and
 * signed ID tokens with real RSA and EC keys.
 */
final class OidcFake
{
    public const ISSUER = 'https://id.example.test';

    public const CLIENT_ID = 'owpanel-client';

    public const CLIENT_SECRET = 'client-secret-1234';

    public const NONCE = 'nonce-from-the-session';

    public OpenSSLAsymmetricKey $rsa;

    public OpenSSLAsymmetricKey $ec;

    public function __construct()
    {
        $this->rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function discovery(array $overrides = []): array
    {
        return array_merge([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/authorize',
            'token_endpoint' => self::ISSUER.'/api/oidc/token',
            'jwks_uri' => self::ISSUER.'/.well-known/jwks.json',
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['plain', 'S256'],
            'id_token_signing_alg_values_supported' => ['RS256', 'ES256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
        ], $overrides);
    }

    /**
     * Fakes discovery and keys; further routes may be added. Replaces any
     * earlier fake, because Laravel would keep using the first match.
     *
     * @param  array<string, mixed>  $discovery
     * @param  array<string, mixed>  $more
     */
    public function fake(array $discovery = [], ?array $keys = null, array $more = []): void
    {
        Http::swap(new Factory);
        Http::fake($more + [
            self::ISSUER.'/.well-known/openid-configuration' => Http::response(self::discovery($discovery)),
            self::ISSUER.'/.well-known/jwks.json' => Http::response(['keys' => $keys ?? $this->jwks()]),
        ]);
    }

    /**
     * @return list<array<string, string>>
     */
    public function jwks(): array
    {
        return [$this->rsaJwk('rsa-1'), $this->ecJwk('ec-1')];
    }

    /**
     * @return array<string, string>
     */
    public function rsaJwk(string $kid, ?OpenSSLAsymmetricKey $key = null): array
    {
        $rsa = openssl_pkey_get_details($key ?? $this->rsa)['rsa'];

        return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => self::b64($rsa['n']), 'e' => self::b64($rsa['e'])];
    }

    /**
     * @return array<string, string>
     */
    public function ecJwk(string $kid): array
    {
        $ec = openssl_pkey_get_details($this->ec)['ec'];

        return ['kty' => 'EC', 'use' => 'sig', 'alg' => 'ES256', 'crv' => 'P-256', 'kid' => $kid,
            'x' => self::b64(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)), 'y' => self::b64(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))];
    }

    /**
     * Valid claims for now, with overrides; null removes a claim.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function claims(array $overrides = []): array
    {
        $now = now()->getTimestamp();
        $claims = array_merge([
            'iss' => self::ISSUER,
            'sub' => 'user-1234',
            'aud' => self::CLIENT_ID,
            'exp' => $now + 300,
            'iat' => $now,
            'nonce' => self::NONCE,
            'email' => 'max@example.test',
        ], $overrides);

        return array_filter($claims, fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function token(array $overrides = [], string $alg = 'RS256', ?string $kid = 'rsa-1', ?OpenSSLAsymmetricKey $key = null): string
    {
        $key ??= $alg === 'ES256' ? $this->ec : $this->rsa;
        openssl_pkey_export($key, $pem);

        return JWT::encode(self::claims($overrides), $pem, $alg, $kid);
    }

    /**
     * A token with any header, signed or not; for tokens the library would
     * refuse to create.
     *
     * @param  array<string, mixed>  $header
     */
    public static function rawToken(array $header, array $claims, string $signature = ''): string
    {
        return JWT::urlsafeB64Encode((string) json_encode($header)).'.'
            .JWT::urlsafeB64Encode((string) json_encode($claims)).'.'
            .JWT::urlsafeB64Encode($signature);
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
