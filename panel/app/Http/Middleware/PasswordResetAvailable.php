<?php

namespace App\Http\Middleware;

use App\Support\MailSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The password reset pages of Fortify exist only while outgoing mail is set
 * up (E-094). Requests for a link are limited per address and per account.
 */
class PasswordResetAvailable
{
    private const ROUTES = ['password.request', 'password.email', 'password.reset', 'password.update'];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()?->getName();
        if (! in_array($route, self::ROUTES, true)) {
            return $next($request);
        }
        abort_unless(MailSettings::configured(), 404);

        if ($route === 'password.email' || $route === 'password.update') {
            $keys = ['reset-ip|'.$request->ip()];
            if ($route === 'password.email') {
                $keys[] = 'reset-mail|'.Str::lower((string) $request->input('email'));
            }
            foreach ($keys as $key) {
                if (RateLimiter::tooManyAttempts($key, 5)) {
                    return back()->withErrors(['email' => __('auth.forgot.throttled')]);
                }
                RateLimiter::hit($key, 600);
            }
        }

        return $next($request);
    }
}
