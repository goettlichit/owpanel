<?php

namespace App\Http\Middleware;

use App\Models\Database;
use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a website is suspended nothing about it may change (E-127): one
 * place for every route of a website or one of its databases, in both
 * areas. Customers can only look. Administrators can lift the suspension,
 * delete the website, restore and take backups, check DNS and export
 * databases; everything else waits until the suspension is lifted.
 *
 * Pages of a suspended website get $suspendedSite, so the layout shows the
 * banner and disables the forms.
 */
class GuardSuspendedSites
{
    /** Requests that only read, for everyone allowed to see the website. */
    private const ALLOWED = ['sites.dns.check', 'my.sites.dns.check'];

    /** Changes administrators may still make, by route name. */
    private const ADMIN_ALLOWED = [
        'sites.suspension.store',
        'sites.suspension.destroy',
        'sites.destroy',
        'sites.backups.store',
        'sites.backups.restore',
        'databases.export',
    ];

    /** Pages whose forms only choose what to show. */
    private const OPEN_PAGES = ['sites.logs', 'my.sites.logs', 'sites.dns', 'my.sites.dns', 'sites.tasks'];

    /** Pages whose forms stay usable for administrators. */
    private const ADMIN_OPEN_PAGES = [
        'sites.suspension',
        'sites.delete',
        'sites.backups',
        'sites.backups.show',
        'databases.transfer',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $site = $this->site($request);
        if ($site === null || ! $site->isSuspended()) {
            return $next($request);
        }

        $name = (string) $request->route()?->getName();
        $admin = $request->user()?->isAdmin() === true;

        if (! $request->isMethodSafe()) {
            if (in_array($name, self::ALLOWED, true) || ($admin && in_array($name, self::ADMIN_ALLOWED, true))) {
                return $next($request);
            }

            return $request->expectsJson()
                ? response()->json(['message' => __('sites.suspension.blocked')], 423)
                : back()->with('error', __('sites.suspension.blocked'));
        }

        View::share('suspendedSite', $site);
        View::share('suspendedFormsOpen', in_array($name, self::OPEN_PAGES, true)
            || ($admin && in_array($name, self::ADMIN_OPEN_PAGES, true)));

        return $next($request);
    }

    private function site(Request $request): ?Site
    {
        $route = $request->route();
        if ($route === null) {
            return null;
        }
        $site = $route->parameter('site');
        if ($site instanceof Site) {
            return $site;
        }
        $database = $route->parameter('database');

        return $database instanceof Database ? $database->site : null;
    }
}
