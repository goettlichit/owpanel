<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * "Logs" and "Tasks" of a website (E-086, E-087). The logs are the end of
 * the nginx access log, the error log or only its PHP messages, read
 * through the query worker; nothing of them is stored.
 */
class SiteLogController extends Controller
{
    /**
     * Route names of the page: "sites." in the administration, "my.sites."
     * in the customer area (E-143).
     */
    protected string $area = 'sites.';

    public function show(Request $request, Site $site): View
    {
        Gate::authorize('viewLogs', $site);

        $kinds = LogController::KINDS['site'];
        $log = array_key_exists((string) $request->query('log'), $kinds) ? (string) $request->query('log') : 'error';
        $lines = in_array((int) $request->query('lines'), LogController::LINES, true) ? (int) $request->query('lines') : LogController::LINES[0];

        return view('sites.logs', [
            'site' => $site->load('server'),
            'area' => $this->area,
            'kinds' => $kinds,
            'log' => $log,
            'lines' => $lines,
        ]);
    }

    public function tasks(Site $site): View
    {
        Gate::authorize('viewTasks', $site);

        return view('sites.tasks', [
            'site' => $site,
            'tasks' => $site->tasks()->with(['server', 'user', 'site'])->latest('created_at')->latest('id')->paginate(20),
            'showServer' => Server::count() > 1,
        ]);
    }
}
