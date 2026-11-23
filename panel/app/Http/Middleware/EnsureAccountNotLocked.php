<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A locked account (E-127) is logged out on its next request, in case a
 * session survived the lock.
 */
class EnsureAccountNotLocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isLocked()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', __('auth.locked'));
        }

        return $next($request);
    }
}
