<?php

namespace App\Servers;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\ServerTask;
use App\Support\PanelRelease;

/**
 * Brings the scripts of a server to the panel's version (E-167, E-187).
 * Called after every status check: a server whose scripts differ gets a
 * task server-scripts-update, at most every 15 minutes, also when it comes
 * back after being offline. A release whose archive is missing loads it
 * first on the panel's own server (panel-update --fetch-archive).
 */
final class ScriptsUpdater
{
    public const RETRY_MINUTES = 15;

    public static function ensure(Server $server): ?ServerTask
    {
        if (! $server->scriptsOutdated() || self::recent($server, ServerScript::ServerScriptsUpdate)) {
            return null;
        }

        $fetch = null;
        if (! ScriptsArchive::available()) {
            $panel = Server::panelHost();
            // A test build without its archive cannot be sent; the alert
            // "server scripts outdated" says so.
            if ($panel === null || PanelRelease::isTestBuild() || self::recent($panel, ServerScript::PanelUpdate, '--fetch-archive')) {
                return null;
            }
            $fetch = ServerTask::start($panel, ScriptCall::panelArchiveFetch(), null);
        }

        return ServerTask::start($server, new ScriptCall(ServerScript::ServerScriptsUpdate, ['--version', PanelRelease::installed()]), null, after: $fetch);
    }

    /**
     * Whether such a task is open or started within the last minutes.
     */
    private static function recent(Server $server, ServerScript $script, ?string $argument = null): bool
    {
        return $server->tasks()
            ->where('script', $script->value)
            ->when($argument !== null, fn ($query) => $query->whereJsonContains('arguments', $argument))
            ->where(fn ($query) => $query
                ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value])
                ->orWhere('created_at', '>=', now()->subMinutes(self::RETRY_MINUTES)))
            ->exists();
    }
}
