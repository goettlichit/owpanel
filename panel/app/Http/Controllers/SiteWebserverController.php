<?php

namespace App\Http\Controllers;

use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Models\ServerTask;
use App\Models\Site;
use App\Servers\ScriptCall;
use App\Sites\WebserverRules;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Own nginx rules of a website (E-184): extra response headers and blocked
 * paths, for administrators only. Every change starts site-nginx, which
 * reads the rules when it runs and checks them with nginx -t.
 */
class SiteWebserverController extends Controller
{
    public function show(Site $site): View
    {
        Gate::authorize('manageWebserver', $site);

        $lastTask = $site->tasks()->where('script', ServerScript::SiteNginx->value)->latest('id')->first();

        return view('sites.webserver', [
            'site' => $site,
            'headers' => WebserverRules::headersText($site),
            'paths' => WebserverRules::pathsText($site),
            'busy' => $site->hasOpenTasks(),
            'lastTask' => $lastTask,
            'state' => WebserverRules::state($site, $lastTask),
        ]);
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('manageWebserver', $site);
        $validated = $request->validate([
            'headers' => ['nullable', 'string', 'max:50000'],
            'paths' => ['nullable', 'string', 'max:20000'],
        ]);

        [$headers, $headerErrors] = WebserverRules::parseHeaders((string) ($validated['headers'] ?? ''));
        [$paths, $pathErrors] = WebserverRules::parsePaths((string) ($validated['paths'] ?? ''));
        if ($headerErrors !== [] || $pathErrors !== []) {
            return back()->withInput()->withErrors(array_filter([
                'headers' => implode(' ', $headerErrors),
                'paths' => implode(' ', $pathErrors),
            ]));
        }

        if ($site->status !== SiteStatus::Active || $site->hasOpenTasks()) {
            return back()->withInput()->with('error', __('sites.webserver.busy'));
        }

        $site->forceFill([
            'nginx_headers' => $headers === [] ? null : $headers,
            'nginx_blocked_paths' => $paths === [] ? null : $paths,
        ]);
        if (! $site->isDirty()) {
            return back();
        }

        DB::transaction(function () use ($request, $site, $headers, $paths) {
            $site->save();
            // Names and counts only; values can be long and say little.
            Audit::record('site.webserver_changed', $site, [
                'domain' => $site->domain,
                'headers' => array_column($headers, 'name'),
                'paths' => count($paths),
            ], $request->user());
            ServerTask::start($site->server, ScriptCall::siteNginx($site->system_user, [], []), $request->user(), $site);
        });

        return redirect()->back(fallback: route('sites.webserver', $site));
    }
}
