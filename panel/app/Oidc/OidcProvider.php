<?php

namespace App\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The OpenID Connect client of the panel (E-113): discovery, the
 * authorization code flow with PKCE, and the checks of the ID token. Only
 * the standard is used, so Pocket ID and any other certified provider work.
 *
 * Every rule from docs/SICHERHEIT.md, "Pocket ID und OIDC", has its place
 * here: https only, issuer compared exactly, signature only with asymmetric
 * algorithms, audience, expiry, issue time and nonce.
 */
class OidcProvider
{
    /**
     * Signature algorithms the panel accepts. Never "none" and never HS*:
     * with those, anyone who knows the client secret could sign a token.
     */
    public const ALGORITHMS = ['RS256', 'RS384', 'RS512', 'PS256', 'ES256', 'ES384', 'EdDSA'];

    /**
     * Seconds of clock difference allowed for exp, iat and nbf.
     */
    public const LEEWAY = 60;

    /**
     * An ID token must be used within this many seconds after it was issued.
     */
    public const MAX_AGE = 600;

    public const SCOPES = 'openid profile email';

    private const CACHE_SECONDS = 3600;

    public function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly ?string $clientSecret = null,
    ) {}

    /**
     * Client with the stored settings.
     */
    public static function fromSettings(): self
    {
        if (! OidcSettings::configured()) {
            throw new OidcException('not_configured');
        }

        return new self((string) OidcSettings::issuer(), (string) OidcSettings::clientId(), OidcSettings::clientSecret());
    }

    /**
     * Checks an address a person entered and returns the issuer exactly as
     * the provider names itself. The address may differ only by a trailing
     * slash; everything else must match (E-113).
     */
    public static function issuerFor(string $address): string
    {
        $address = trim($address);
        $parts = parse_url($address);
        if (strlen($address) > 255 || $parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || filter_var($address, FILTER_VALIDATE_URL) === false) {
            throw new OidcException('invalid_issuer', $address);
        }

        $document = self::getJson(self::discoveryUrl($address), 'discovery_failed');
        $issuer = $document['issuer'] ?? null;
        if (! is_string($issuer) || rtrim($issuer, '/') !== rtrim($address, '/')) {
            throw new OidcException('issuer_mismatch', 'Issuer '.(is_string($issuer) ? $issuer : '?').' for '.$address);
        }

        return $issuer;
    }

    /**
     * The discovery document, checked. Cached for an hour.
     *
     * @return array{authorization_endpoint: string, token_endpoint: string, jwks_uri: string, algorithms: list<string>, pkce: bool, auth_methods: list<string>}
     */
    public function discovery(bool $fresh = false): array
    {
        $key = 'owpanel.oidc.discovery.'.sha1($this->issuer);
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_SECONDS, function (): array {
            $document = self::getJson(self::discoveryUrl($this->issuer), 'discovery_failed');

            if (($document['issuer'] ?? null) !== $this->issuer) {
                throw new OidcException('issuer_mismatch', 'Discovery names '.json_encode($document['issuer'] ?? null));
            }
            $endpoints = [];
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $name) {
                $url = $document[$name] ?? null;
                if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                    throw new OidcException('discovery_failed', "Missing {$name}");
                }
                if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                    throw new OidcException('insecure_endpoint', "{$name} {$url}");
                }
                $endpoints[$name] = $url;
            }
            if (! in_array('code', self::stringList($document['response_types_supported'] ?? []), true)) {
                throw new OidcException('no_code_flow');
            }
            // Absent means unknown; the panel sends S256 anyway.
            $pkce = self::stringList($document['code_challenge_methods_supported'] ?? []);
            if (isset($document['code_challenge_methods_supported']) && ! in_array('S256', $pkce, true)) {
                throw new OidcException('no_pkce');
            }
            $algorithms = array_values(array_intersect(self::ALGORITHMS, self::stringList($document['id_token_signing_alg_values_supported'] ?? [])));
            if ($algorithms === []) {
                throw new OidcException('no_signing_alg');
            }

            return $endpoints + [
                'algorithms' => $algorithms,
                'pkce' => in_array('S256', $pkce, true),
                'auth_methods' => self::stringList($document['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic']),
            ];
        });
    }

    /**
     * Reads discovery and keys afresh, for "Check connection" and before
     * the settings are saved.
     *
     * @return array{algorithms: list<string>, pkce: bool, keys: int}
     */
    public function check(): array
    {
        $discovery = $this->discovery(fresh: true);

        return [
            'algorithms' => $discovery['algorithms'],
            'pkce' => $discovery['pkce'],
            'keys' => count($this->keys(fresh: true)),
        ];
    }

    /**
     * Where the browser goes to log in. State, nonce and the PKCE verifier
     * are new for every login and stay in the session. $extra adds request
     * parameters.
     *
     * @param  array<string, string|int>  $extra
     */
    public function authorizationUrl(string $redirectUri, string $state, string $nonce, #[\SensitiveParameter] string $codeVerifier, array $extra = []): string
    {
        $endpoint = $this->discovery()['authorization_endpoint'];
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
            ...$extra,
        ], '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * PKCE: the challenge is the SHA-256 of the verifier, base64url without
     * padding (RFC 7636).
     */
    public static function codeChallenge(#[\SensitiveParameter] string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * A random value for state, nonce or the PKCE verifier: 43 characters
     * from 32 random bytes.
     */
    public static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Exchanges the code from the callback for the ID token.
     */
    public function exchangeCode(#[\SensitiveParameter] string $code, string $redirectUri, #[\SensitiveParameter] string $codeVerifier): string
    {
        $discovery = $this->discovery();
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ];
        $request = self::http()->asForm();
        $secret = (string) $this->clientSecret;
        if (in_array('client_secret_basic', $discovery['auth_methods'], true)) {
            // RFC 6749, 2.3.1: both parts form-encoded before base64.
            $request = $request->withHeaders([
                'Authorization' => 'Basic '.base64_encode(urlencode($this->clientId).':'.urlencode($secret)),
            ]);
        } elseif (in_array('client_secret_post', $discovery['auth_methods'], true)) {
            $form += ['client_id' => $this->clientId, 'client_secret' => $secret];
        } else {
            throw new OidcException('token_failed', 'No supported client authentication');
        }

        try {
            $response = $request->post($discovery['token_endpoint'], $form);
        } catch (ConnectionException) {
            throw new OidcException('connection_failed', $discovery['token_endpoint']);
        }
        if (! $response->successful()) {
            throw new OidcException('token_failed', 'HTTP '.$response->status().' '.json_encode($response->json('error')));
        }
        $idToken = $response->json('id_token');
        if (! is_string($idToken) || $idToken === '') {
            throw new OidcException('token_failed', 'No ID token');
        }

        return $idToken;
    }

    /**
     * Checks an ID token and returns its claims. Throws invalid_token with
     * the reason in the message for the log.
     *
     * @return array<string, mixed>
     */
    public function verifyIdToken(string $idToken, string $nonce): array
    {
        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            throw new OidcException('invalid_token', 'Not a signed JWT');
        }
        try {
            $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new OidcException('invalid_token', 'Unreadable header');
        }
        $alg = is_array($header) ? ($header['alg'] ?? null) : null;
        if (! is_string($alg) || ! in_array($alg, $this->discovery()['algorithms'], true)) {
            throw new OidcException('invalid_token', 'Algorithm '.json_encode($alg).' not allowed');
        }
        $kid = isset($header['kid']) && is_string($header['kid']) ? $header['kid'] : null;

        $keys = $this->keySet($alg, fresh: false);
        if ($kid !== null && ! isset($keys[$kid])) {
            // The provider may have rotated its keys: load them once more.
            $keys = $this->keySet($alg, fresh: true);
        }
        if ($kid !== null) {
            $key = $keys[$kid] ?? throw new OidcException('invalid_token', 'Unknown key '.$kid);
        } elseif (count($keys) === 1) {
            $key = reset($keys);
        } else {
            throw new OidcException('invalid_token', 'No key ID with several keys');
        }

        $leeway = JWT::$leeway;
        $timestamp = JWT::$timestamp;
        JWT::$leeway = self::LEEWAY;
        JWT::$timestamp = now()->getTimestamp();
        try {
            $claims = json_decode((string) json_encode(JWT::decode($idToken, $key)), true);
        } catch (Throwable $e) {
            throw new OidcException('invalid_token', $e->getMessage());
        } finally {
            JWT::$leeway = $leeway;
            JWT::$timestamp = $timestamp;
        }

        $this->checkClaims(is_array($claims) ? $claims : [], $nonce);

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function checkClaims(array $claims, string $nonce): void
    {
        $now = now()->getTimestamp();
        $fail = fn (string $reason) => throw new OidcException('invalid_token', $reason);

        if (($claims['iss'] ?? null) !== $this->issuer) {
            $fail('Issuer '.json_encode($claims['iss'] ?? null));
        }
        $audience = $claims['aud'] ?? null;
        $audiences = is_string($audience) ? [$audience] : (is_array($audience) ? $audience : []);
        if (! in_array($this->clientId, $audiences, true)) {
            $fail('Audience');
        }
        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->clientId) {
            $fail('Authorized party missing with several audiences');
        }
        if (isset($claims['azp']) && $claims['azp'] !== $this->clientId) {
            $fail('Authorized party');
        }
        // The library checks exp and iat only when they are present.
        if (! is_numeric($claims['exp'] ?? null)) {
            $fail('No expiry');
        }
        if (! is_numeric($claims['iat'] ?? null) || (int) $claims['iat'] < $now - self::MAX_AGE - self::LEEWAY) {
            $fail('Issue time missing or too old');
        }
        if (! is_string($claims['nonce'] ?? null) || $nonce === '' || ! hash_equals($nonce, $claims['nonce'])) {
            $fail('Nonce');
        }
        $subject = $claims['sub'] ?? null;
        if (! is_string($subject) || $subject === '' || strlen($subject) > 255) {
            $fail('Subject');
        }
    }

    /**
     * Signing keys of the provider for one algorithm, by key ID.
     *
     * @return array<string, Key>
     */
    private function keySet(string $alg, bool $fresh): array
    {
        $keys = array_filter($this->keys($fresh), fn (array $key) => ($key['use'] ?? 'sig') === 'sig'
            && (! isset($key['alg']) || $key['alg'] === $alg));
        try {
            return $keys === [] ? [] : JWK::parseKeySet(['keys' => array_values($keys)], $alg);
        } catch (Throwable $e) {
            throw new OidcException('invalid_token', 'Keys: '.$e->getMessage());
        }
    }

    /**
     * The JSON Web Key Set of the provider. Cached for an hour.
     *
     * @return list<array<string, mixed>>
     */
    private function keys(bool $fresh): array
    {
        $key = 'owpanel.oidc.jwks.'.sha1($this->issuer);
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_SECONDS, function (): array {
            $keys = self::getJson($this->discovery()['jwks_uri'], 'jwks_failed')['keys'] ?? null;
            if (! is_array($keys) || $keys === [] || ! array_is_list($keys)) {
                throw new OidcException('jwks_failed', 'No keys');
            }

            return array_values(array_filter($keys, 'is_array'));
        });
    }

    private static function discoveryUrl(string $issuer): string
    {
        return rtrim($issuer, '/').'/.well-known/openid-configuration';
    }

    /**
     * @return array<string, mixed>
     */
    private static function getJson(string $url, string $errorCode): array
    {
        try {
            $response = self::http()->get($url);
        } catch (ConnectionException) {
            throw new OidcException('connection_failed', $url);
        }
        $data = $response->successful() ? $response->json() : null;
        if (! is_array($data)) {
            throw new OidcException($errorCode, 'HTTP '.$response->status().' from '.$url);
        }

        return $data;
    }

    /**
     * No redirects: every address must be the one the provider names.
     */
    private static function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(10)
            ->connectTimeout(5)
            ->withUserAgent('OWPanel')
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
