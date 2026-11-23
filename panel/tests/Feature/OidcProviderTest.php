<?php

namespace Tests\Feature;

use App\Oidc\OidcException;
use App\Oidc\OidcProvider;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\OidcFake;
use Tests\TestCase;

/**
 * The OpenID Connect client (E-113): every rule from docs/SICHERHEIT.md,
 * "Pocket ID und OIDC", has a test that a token breaking it is refused.
 */
class OidcProviderTest extends TestCase
{
    private OidcFake $idp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idp = new OidcFake;
    }

    private function provider(): OidcProvider
    {
        return new OidcProvider(OidcFake::ISSUER, OidcFake::CLIENT_ID, OidcFake::CLIENT_SECRET);
    }

    private function assertRejected(string $token, string $nonce = OidcFake::NONCE, string $code = 'invalid_token'): void
    {
        try {
            $this->provider()->verifyIdToken($token, $nonce);
            $this->fail('The token was accepted.');
        } catch (OidcException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    public function test_accepts_a_valid_rs256_token(): void
    {
        $this->idp->fake();

        $claims = $this->provider()->verifyIdToken($this->idp->token(), OidcFake::NONCE);

        $this->assertSame('user-1234', $claims['sub']);
        $this->assertSame('max@example.test', $claims['email']);
    }

    public function test_accepts_a_valid_es256_token(): void
    {
        $this->idp->fake();

        $claims = $this->provider()->verifyIdToken($this->idp->token([], 'ES256', 'ec-1'), OidcFake::NONCE);

        $this->assertSame('user-1234', $claims['sub']);
    }

    public function test_accepts_a_token_without_key_id_when_there_is_one_key(): void
    {
        $this->idp->fake(keys: [$this->idp->rsaJwk('only')]);

        $claims = $this->provider()->verifyIdToken($this->idp->token([], 'RS256', null), OidcFake::NONCE);

        $this->assertSame('user-1234', $claims['sub']);
    }

    public function test_rejects_a_token_from_another_issuer(): void
    {
        $this->idp->fake();

        $this->assertRejected($this->idp->token(['iss' => 'https://evil.example.test']));
        $this->assertRejected($this->idp->token(['iss' => OidcFake::ISSUER.'/']));
        $this->assertRejected($this->idp->token(['iss' => null]));
    }

    public function test_rejects_a_token_for_another_client(): void
    {
        $this->idp->fake();

        $this->assertRejected($this->idp->token(['aud' => 'other-client']));
        $this->assertRejected($this->idp->token(['aud' => null]));
    }

    public function test_several_audiences_need_the_authorized_party(): void
    {
        $this->idp->fake();

        $this->assertRejected($this->idp->token(['aud' => [OidcFake::CLIENT_ID, 'other-client']]));
        $this->assertRejected($this->idp->token(['aud' => [OidcFake::CLIENT_ID, 'other-client'], 'azp' => 'other-client']));
        $this->assertRejected($this->idp->token(['azp' => 'other-client']));

        $claims = $this->provider()->verifyIdToken(
            $this->idp->token(['aud' => [OidcFake::CLIENT_ID, 'other-client'], 'azp' => OidcFake::CLIENT_ID]),
            OidcFake::NONCE,
        );
        $this->assertSame('user-1234', $claims['sub']);
    }

    public function test_rejects_expired_tokens_beyond_the_leeway(): void
    {
        $this->idp->fake();
        $token = $this->idp->token();

        $this->travel(OidcProvider::LEEWAY + 299)->seconds();
        $this->assertSame('user-1234', $this->provider()->verifyIdToken($token, OidcFake::NONCE)['sub']);

        $this->travel(2)->seconds();
        $this->assertRejected($token);
    }

    public function test_rejects_tokens_without_expiry_or_issue_time_and_old_ones(): void
    {
        $this->idp->fake();
        $now = now()->getTimestamp();

        $this->assertRejected($this->idp->token(['exp' => null]));
        $this->assertRejected($this->idp->token(['iat' => null]));
        $this->assertRejected($this->idp->token(['iat' => $now - OidcProvider::MAX_AGE - OidcProvider::LEEWAY - 5, 'exp' => $now + 300]));
        // Issued in the future.
        $this->assertRejected($this->idp->token(['iat' => $now + OidcProvider::LEEWAY + 30]));
    }

    public function test_rejects_a_wrong_or_missing_nonce(): void
    {
        $this->idp->fake();

        $this->assertRejected($this->idp->token(), 'another-nonce');
        $this->assertRejected($this->idp->token(), '');
        $this->assertRejected($this->idp->token(['nonce' => null]));
    }

    public function test_rejects_tokens_without_a_usable_subject(): void
    {
        $this->idp->fake();

        $this->assertRejected($this->idp->token(['sub' => null]));
        $this->assertRejected($this->idp->token(['sub' => '']));
        $this->assertRejected($this->idp->token(['sub' => str_repeat('a', 256)]));
    }

    public function test_rejects_unsigned_tokens(): void
    {
        $this->idp->fake();

        $this->assertRejected(OidcFake::rawToken(['alg' => 'none', 'typ' => 'JWT'], OidcFake::claims()));
        $this->assertRejected(OidcFake::rawToken(['alg' => 'none', 'kid' => 'rsa-1'], OidcFake::claims()));
        $this->assertRejected('not-a-token');
    }

    public function test_rejects_symmetric_signatures_even_with_the_public_key_as_secret(): void
    {
        $this->idp->fake();
        $publicPem = openssl_pkey_get_details($this->idp->rsa)['key'];

        // The classic algorithm confusion: HS256 keyed with the public key,
        // or with the client secret the provider shares with the panel.
        foreach ([$publicPem, OidcFake::CLIENT_SECRET] as $secret) {
            $header = ['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'rsa-1'];
            $unsigned = JWT::urlsafeB64Encode((string) json_encode($header)).'.'.JWT::urlsafeB64Encode((string) json_encode(OidcFake::claims()));
            $this->assertRejected(OidcFake::rawToken($header, OidcFake::claims(), hash_hmac('sha256', $unsigned, $secret, true)));
        }
    }

    public function test_rejects_a_signature_of_another_key(): void
    {
        $this->idp->fake();
        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        $this->assertRejected($this->idp->token([], 'RS256', 'rsa-1', $other));
        // RS256 header but the EC key ID.
        $this->assertRejected($this->idp->token([], 'RS256', 'ec-1'));
    }

    public function test_rejects_algorithms_the_provider_does_not_announce(): void
    {
        $this->idp->fake(['id_token_signing_alg_values_supported' => ['RS256']]);

        $this->assertRejected($this->idp->token([], 'ES256', 'ec-1'));
    }

    public function test_an_unknown_key_id_reloads_the_keys_once(): void
    {
        $rotated = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        Http::fake([
            OidcFake::ISSUER.'/.well-known/openid-configuration' => Http::response(OidcFake::discovery()),
            OidcFake::ISSUER.'/.well-known/jwks.json' => Http::sequence()
                ->push(['keys' => [$this->idp->rsaJwk('rsa-1')]])
                ->push(['keys' => [$this->idp->rsaJwk('rsa-1'), $this->idp->rsaJwk('rsa-2', $rotated)]])
                ->push(['keys' => [$this->idp->rsaJwk('rsa-1'), $this->idp->rsaJwk('rsa-2', $rotated)]]),
        ]);
        $provider = $this->provider();

        $provider->verifyIdToken($this->idp->token(), OidcFake::NONCE);
        $claims = $provider->verifyIdToken($this->idp->token([], 'RS256', 'rsa-2', $rotated), OidcFake::NONCE);
        $this->assertSame('user-1234', $claims['sub']);

        // A key that never appears: one reload, then refused.
        $this->assertRejected($this->idp->token([], 'RS256', 'rsa-9'));
        Http::assertSentCount(4);
    }

    public function test_discovery_must_name_the_same_issuer_and_https_endpoints(): void
    {
        $cases = [
            'issuer_mismatch' => ['issuer' => 'https://other.example.test'],
            'insecure_endpoint' => ['token_endpoint' => 'http://id.example.test/token'],
            'no_code_flow' => ['response_types_supported' => ['id_token']],
            'no_pkce' => ['code_challenge_methods_supported' => ['plain']],
            'no_signing_alg' => ['id_token_signing_alg_values_supported' => ['HS256', 'none']],
            'discovery_failed' => ['jwks_uri' => null],
        ];
        foreach ($cases as $code => $discovery) {
            $this->idp->fake($discovery);
            try {
                $this->provider()->check();
                $this->fail("{$code} was accepted.");
            } catch (OidcException $e) {
                $this->assertSame($code, $e->errorCode);
            }
        }
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake([
            OidcFake::ISSUER.'/.well-known/openid-configuration' => Http::response('', 302, ['Location' => 'https://evil.example.test/conf']),
            '*' => Http::response(OidcFake::discovery(['issuer' => 'https://evil.example.test'])),
        ]);

        try {
            $this->provider()->check();
            $this->fail('A redirect was followed.');
        } catch (OidcException $e) {
            $this->assertSame('discovery_failed', $e->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_check_reports_algorithms_pkce_and_keys(): void
    {
        $this->idp->fake();

        $this->assertSame(['algorithms' => ['RS256', 'ES256'], 'pkce' => true, 'keys' => 2], $this->provider()->check());
    }

    public function test_the_issuer_comes_from_the_provider_and_must_match_the_address(): void
    {
        $this->idp->fake();

        $this->assertSame(OidcFake::ISSUER, OidcProvider::issuerFor(OidcFake::ISSUER));
        $this->assertSame(OidcFake::ISSUER, OidcProvider::issuerFor(' '.OidcFake::ISSUER.'/ '));

        foreach (['http://id.example.test', 'https://id.example.test?x=1', 'https://id.example.test#a', 'https://user:pw@id.example.test', 'id.example.test', 'javascript:alert(1)'] as $address) {
            try {
                OidcProvider::issuerFor($address);
                $this->fail("{$address} was accepted.");
            } catch (OidcException $e) {
                $this->assertSame('invalid_issuer', $e->errorCode, $address);
            }
        }
    }

    public function test_the_authorization_url_carries_pkce_state_and_nonce(): void
    {
        $this->idp->fake();
        $verifier = OidcProvider::randomToken();

        $url = $this->provider()->authorizationUrl('https://panel.example.test/auth/oidc/callback', 'the-state', 'the-nonce', $verifier);

        $this->assertStringStartsWith(OidcFake::ISSUER.'/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame([
            'response_type' => 'code',
            'client_id' => OidcFake::CLIENT_ID,
            'redirect_uri' => 'https://panel.example.test/auth/oidc/callback',
            'scope' => 'openid profile email',
            'state' => 'the-state',
            'nonce' => 'the-nonce',
            'code_challenge' => OidcProvider::codeChallenge($verifier),
            'code_challenge_method' => 'S256',
        ], $query);
        $this->assertStringNotContainsString($verifier, $url);
        $this->assertSame(43, strlen($verifier));
    }

    public function test_pkce_challenge_follows_rfc_7636(): void
    {
        // Example from RFC 7636, appendix B.
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', OidcProvider::codeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    public function test_the_code_is_exchanged_with_basic_authentication_and_the_verifier(): void
    {
        $this->idp->fake(more: [
            OidcFake::ISSUER.'/api/oidc/token' => Http::response(['access_token' => 'x', 'token_type' => 'Bearer', 'id_token' => 'the-id-token']),
        ]);
        $provider = new OidcProvider(OidcFake::ISSUER, 'client:1', 'se cret&');

        $this->assertSame('the-id-token', $provider->exchangeCode('the-code', 'https://panel.example.test/auth/oidc/callback', 'the-verifier'));

        Http::assertSent(function (Request $request) {
            return $request->url() === OidcFake::ISSUER.'/api/oidc/token'
                && $request->header('Authorization')[0] === 'Basic '.base64_encode('client%3A1:se+cret%26')
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'the-code'
                && $request['code_verifier'] === 'the-verifier'
                && $request['redirect_uri'] === 'https://panel.example.test/auth/oidc/callback'
                && ! isset($request['client_secret']);
        });
    }

    public function test_the_secret_goes_in_the_form_when_the_provider_wants_it_there(): void
    {
        $this->idp->fake(['token_endpoint_auth_methods_supported' => ['client_secret_post']], more: [
            OidcFake::ISSUER.'/api/oidc/token' => Http::response(['id_token' => 'the-id-token']),
        ]);

        $this->provider()->exchangeCode('the-code', 'https://panel.example.test/auth/oidc/callback', 'the-verifier');

        Http::assertSent(fn (Request $request) => $request->url() === OidcFake::ISSUER.'/api/oidc/token'
            && $request['client_id'] === OidcFake::CLIENT_ID
            && $request['client_secret'] === OidcFake::CLIENT_SECRET
            && ! $request->hasHeader('Authorization'));
    }

    public function test_a_refused_code_is_reported(): void
    {
        $this->idp->fake(more: [
            OidcFake::ISSUER.'/api/oidc/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        try {
            $this->provider()->exchangeCode('the-code', 'https://panel.example.test/auth/oidc/callback', 'the-verifier');
            $this->fail('A refused code was accepted.');
        } catch (OidcException $e) {
            $this->assertSame('token_failed', $e->errorCode);
        }
    }
}
