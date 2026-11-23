<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administrators who signed in with a password must set up two-factor
 * authentication before they can use the panel (docs/SICHERHEIT.md,
 * "Anmeldung am Panel", rule 1). Until then only the account page with
 * the setup, the password confirmation, preferences and logout work.
 *
 * Administrators cannot switch two-factor authentication off; a lost
 * device is handled with the recovery codes.
 *
 * A sign-in through Pocket ID will set the session value "auth.via" to
 * "oidc" and is exempt (E-017).
 */
class EnsureTwoFactorForAdmins
{
    /** @var list<string> */
    private const ALLOWED_ROUTES = [
        'account.show',
        'account.security',
        // A start password is changed first (E-081).
        'user-password.update',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'preferences.locale',
        'preferences.theme',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isAdmin() && $request->routeIs('two-factor.disable')) {
            abort(403, __('account.two_factor.admin_cannot_disable'));
        }

        if ($user === null
            || ! $user->isAdmin()
            || $user->hasConfirmedTwoFactor()
            || $request->session()->get('auth.via') === 'oidc'
            || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, __('account.two_factor.required'));
        }

        return redirect()->route('account.security')
            ->with('warning', __('account.two_factor.required'));
    }
}
