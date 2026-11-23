<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Support\LogTail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Logs of the servers (server menu) and of the panel itself (System), E-087.
 * Website logs are in SiteLogController. Server logs are read through the
 * query worker; the panel's own logs are files it can read itself.
 */
class LogController extends Controller
{
    public const LINES = [100, 500, 1000];

    /**
     * Logs per kind of source, in the order of the switch.
     */
    public const KINDS = [
        'panel' => ['panel' => 'layout-dashboard', 'worker' => 'player-play', 'query' => 'database-search'],
        'server' => ['auth' => 'key', 'fail2ban' => 'shield-lock', 'nginx' => 'alert-triangle', 'system' => 'terminal-2'],
        'site' => ['access' => 'world', 'error' => 'alert-triangle', 'php' => 'brand-php', 'cron' => 'clock'],
    ];

    /**
     * Files of the panel's own logs, below storage/logs.
     */
    private const PANEL_FILES = ['panel' => 'laravel.log', 'worker' => 'worker.log', 'query' => 'query.log'];

    /**
     * Logs of one server: sign-ins, fail2ban, the global nginx error log and
     * the system journal, read through the query worker.
     */
    public function server(Request $request, Server $server): View
    {
        Gate::authorize('view', $server);

        [$log, $lines] = $this->choice($request, 'server');

        return view('servers.logs', [
            'server' => $server,
            'kinds' => self::KINDS['server'],
            'log' => $log,
            'lines' => $lines,
        ]);
    }

    /**
     * The panel's own logs under System; files it can read itself.
     */
    public function panel(Request $request): View
    {
        Gate::authorize('viewAny', Server::class);

        [$log, $lines] = $this->choice($request, 'panel');

        return view('system.logs', [
            'kinds' => self::KINDS['panel'],
            'log' => $log,
            'lines' => $lines,
            'answer' => LogTail::read(storage_path('logs/'.self::PANEL_FILES[$log]), $lines),
        ]);
    }

    /**
     * The chosen log and line count; unknown values fall back to the first.
     *
     * @return array{0: string, 1: int}
     */
    private function choice(Request $request, string $type): array
    {
        $kinds = self::KINDS[$type];
        $log = array_key_exists((string) $request->query('log'), $kinds) ? (string) $request->query('log') : array_key_first($kinds);
        $lines = in_array((int) $request->query('lines'), self::LINES, true) ? (int) $request->query('lines') : self::LINES[0];

        return [$log, $lines];
    }
}
