<?php

namespace App\Http\Controllers;

use App\Models\OidcIdentity;
use App\Oidc\OidcException;
use App\Oidc\OidcProvider;
use App\Oidc\OidcSettings;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Login with Pocket ID or another OpenID Connect provider and linking of
 * the own account (E-113, E-114, docs/SICHERHEIT.md "Pocket ID und OIDC").
 *
 * Both start the same flow: state, nonce and PKCE verifier are new for
 * every attempt and live only in the session, the callback takes them out
 * once. A login finds the account by issuer and "sub" only; it never
 * creates accounts or grants rights. Linking needs an active
 * login in the panel. Customer accounts use Pocket ID only after an
 * administrator turned it on.
 */
class OidcController extends Controller
{
    private const SESSION = 'oidc.flow';

    /**
     * A flow must come back within this many seconds.
     */
    private const MAX_FLOW_SECONDS = 600;

    /**
     * "Log in with ..." on the login page.
     */
    public function redirect(Request $request): RedirectResponse
    {
        return $this->start($request, 'login');
    }

    /**
     * "Link" on the security page of the own account.
     */
    public function link(Request $request): RedirectResponse
    {
        if (! $request->user()->mayUseOidc()) {
            return $this->fail('link', 'not_allowed');
        }

        return $this->start($request, 'link');
    }

    public function unlink(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->oidcIdentities()->delete() > 0) {
            Audit::record('account.oidc_unlinked', $user, user: $user);
        }

        return redirect()->route('account.security')
            ->with('status', __('auth.oidc.unlinked', ['name' => OidcSettings::name()]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $flow = $request->session()->pull(self::SESSION);
        if (! is_array($flow) || now()->getTimestamp() - (int) ($flow['started_at'] ?? 0) > self::MAX_FLOW_SECONDS) {
            return $this->fail($request->user() ? 'link' : 'login', 'expired');
        }
        $intent = $flow['intent'];

        if (! is_string($request->query('state')) || ! hash_equals($flow['state'], $request->query('state'))) {
            Log::warning('OIDC callback with a wrong state.');

            return $this->fail($intent, 'failed');
        }
        if ($request->query('error') !== null) {
            return $this->fail($intent, 'cancelled');
        }
        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 2048) {
            return $this->fail($intent, 'failed');
        }

        try {
            $provider = OidcProvider::fromSettings();
            $claims = $provider->verifyIdToken(
                $provider->exchangeCode($code, OidcSettings::redirectUrl(), $flow['verifier']),
                $flow['nonce'],
            );
        } catch (OidcException $e) {
            Log::warning('OIDC '.$intent.' failed: '.$e->errorCode.': '.$e->getMessage());
            if ($intent !== 'link') {
                Audit::record('auth.oidc_failed', $request->user());
            }

            return $this->fail($intent, in_array($e->errorCode, ['not_configured', 'connection_failed'], true) ? $e->errorCode : 'failed');
        }

        $issuer = (string) OidcSettings::issuer();
        $email = is_string($claims['email'] ?? null) && strlen($claims['email']) <= 255 ? $claims['email'] : null;

        return match ($intent) {
            'link' => $this->finishLink($request, $flow, $issuer, $claims['sub'], $email),
            default => $this->finishLogin($request, $issuer, $claims['sub'], $email),
        };
    }

    /**
     * @param  array<string, string|int>  $extra
     */
    private function start(Request $request, string $intent, array $extra = []): RedirectResponse
    {
        $state = OidcProvider::randomToken();
        $nonce = OidcProvider::randomToken();
        $verifier = OidcProvider::randomToken();

        try {
            $url = OidcProvider::fromSettings()->authorizationUrl(OidcSettings::redirectUrl(), $state, $nonce, $verifier, $extra);
        } catch (OidcException $e) {
            Log::warning('OIDC '.$intent.' could not start: '.$e->errorCode.': '.$e->getMessage());

            return $this->fail($intent, in_array($e->errorCode, ['not_configured', 'connection_failed'], true) ? $e->errorCode : 'failed');
        }

        $request->session()->put(self::SESSION, [
            'intent' => $intent,
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'user_id' => $intent === 'login' ? null : $request->user()->getKey(),
            'started_at' => now()->getTimestamp(),
        ]);

        return redirect()->away($url);
    }

    private function finishLogin(Request $request, string $issuer, string $subject, ?string $email): RedirectResponse
    {
        $identity = OidcIdentity::where('issuer', $issuer)->where('subject', $subject)->first();
        if ($identity === null) {
            Audit::record('auth.oidc_failed');

            return $this->fail('login', 'not_linked');
        }
        if (! $identity->user->mayUseOidc()) {
            Audit::record('auth.oidc_failed', $identity->user);

            return $this->fail('login', 'not_allowed');
        }
        // A locked account cannot log in with Pocket ID either (E-127).
        if ($identity->user->isLocked()) {
            Audit::record('auth.oidc_failed', $identity->user);

            return redirect()->route('login')->with('error', __('auth.locked'));
        }

        // Read by the login listener and by the two-factor rule (E-017).
        $request->session()->put('auth.via', 'oidc');
        Auth::login($identity->user);
        $request->session()->regenerate();
        $identity->forceFill(['email' => $email ?? $identity->email, 'last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * @param  array<string, mixed>  $flow
     */
    private function finishLink(Request $request, array $flow, string $issuer, string $subject, ?string $email): RedirectResponse
    {
        $user = $request->user();
        if ($user === null || $user->getKey() !== $flow['user_id']) {
            return $this->fail('login', 'expired');
        }
        if (! $user->mayUseOidc()) {
            return $this->fail('link', 'not_allowed');
        }

        $existing = OidcIdentity::where('issuer', $issuer)->where('subject', $subject)->first();
        if ($existing !== null && $existing->user_id !== $user->getKey()) {
            // Name the account that came back: usually someone is still
            // signed in at Pocket ID, or the device offered another passkey.
            return $this->fail('link', $email === null ? 'taken' : 'taken_account', ['account' => $email]);
        }
        if ($existing === null) {
            $user->oidcIdentities()->where('issuer', $issuer)->delete();
            $user->oidcIdentities()->create(['issuer' => $issuer, 'subject' => $subject, 'email' => $email]);
        }
        Audit::record('account.oidc_linked', $user, user: $user);

        return redirect()->route('account.security')
            ->with('status', __('auth.oidc.linked', ['name' => OidcSettings::name()]));
    }

    /**
     * @param  array<string, string|null>  $replace
     */
    private function fail(string $intent, string $reason, array $replace = []): RedirectResponse
    {
        $message = __('auth.oidc.errors.'.$reason, $replace + ['name' => OidcSettings::name()]);

        return match ($intent) {
            'link' => redirect()->route('account.security')->with('error', $message),
            default => redirect()->route('login')->with('error', $message),
        };
    }
}
