<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts created by another administrator must change their start
 * password before anything else (E-081). Until then only the account page
 * with the password form, preferences and logout work.
 */
class EnsurePasswordChanged
{
    /** @var list<string> */
    private const ALLOWED_ROUTES = [
        'account.show',
        'user-password.update',
        'preferences.locale',
        'preferences.theme',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->password_change_required || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, __('account.password.change_required'));
        }

        return redirect()->route('account.show')
            ->with('warning', __('account.password.change_required'));
    }
}
