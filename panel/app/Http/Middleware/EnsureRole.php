<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Separates the administration from the customer area (E-143): routes of
 * the administration need role "admin", routes under /my role "customer".
 * The policies still decide about every single object; this is a second
 * line, so a policy that also allows customers never opens a page of the
 * administration with servers, Linux users and task logs (docs/SICHERHEIT.md,
 * "Kundenbereich", rule 4).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        abort_unless($user !== null && $user->role->value === $role, 403);

        return $next($request);
    }
}
