<?php

namespace App\Backups;

use App\Enums\SiteStatus;
use App\Models\Database;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Sites\PhpSettings;
use App\Sites\WebserverRules;
use App\Support\Audit;
use Illuminate\Support\Collection;

/**
 * Builds websites again that the panel knows but a server lost, e.g. after
 * the panel was restored on a new server (E-106). Everything comes from
 * the panel and the backups: each step is an ordinary server task that
 * waits for the one before it, so every step has its status and log, and a
 * failed step stops the rest of its website.
 */
final class Rebuild
{
    /**
     * The tasks for one website, in this order: create the website without
     * a certificate, create its databases with their stored passwords,
     * restore files and databases from the backup, write cron jobs and
     * SFTP access, and request the certificate last, so a DNS problem does
     * not hold back the data; a suspended website is suspended again at the
     * very end. Returns the first task.
     *
     * @param  string  $snapshot  ID of a backup, or ScriptCall::LATEST
     */
    public static function site(Site $site, string $snapshot, ?User $user, ?ServerTask $after = null): ServerTask
    {
        $server = $site->server;

        $first = $previous = ServerTask::start($server, ScriptCall::siteCreate($site->domain, $site->system_user, false, $site->www, php: $server->phpFor($site), settings: PhpSettings::effective($site)), $user, $site, after: $after);
        // Own nginx rules, read when the task runs (E-184).
        if (WebserverRules::any($site)) {
            $previous = ServerTask::start($server, ScriptCall::siteNginx($site->system_user, [], []), $user, $site, after: $previous);
        }

        $site->databases()->where('status', SiteStatus::Active->value)->orderBy('name')->get()
            ->each(function (Database $database) use ($server, $site, $user, &$previous) {
                $previous = ServerTask::start($server, ScriptCall::dbCreate($database->name), $user, $site, $database, after: $previous);
            });

        $previous = ServerTask::start($server, ScriptCall::siteRestore($site->system_user, $snapshot, 'all'), $user, $site, after: $previous);

        // Both read their data when they run (E-088, E-089).
        if ($site->cronJobs()->where('enabled', true)->exists()) {
            $previous = ServerTask::start($server, ScriptCall::siteCron($site->system_user, []), $user, $site, after: $previous);
        }
        if ($site->sftp_enabled) {
            $previous = ServerTask::start($server, ScriptCall::siteSftp($site->system_user, false), $user, $site, after: $previous);
        }
        if ($site->tls) {
            $previous = ServerTask::start($server, ScriptCall::siteCreate($site->domain, $site->system_user, true, $site->www), $user, $site, after: $previous);
        }
        // A suspended website comes back suspended (E-127).
        if ($site->isSuspended()) {
            ServerTask::start($server, ScriptCall::siteSuspend($site->system_user), $user, $site, after: $previous);
        }

        Audit::record('site.rebuild_started', $site, [
            'domain' => $site->domain,
            'snapshot' => $snapshot,
        ], $user);

        return $first;
    }

    /**
     * Rebuilds all websites the server lacks, after putting back the own
     * firewall rules and fail2ban exceptions. Returns the websites.
     *
     * @return Collection<int, Site>
     */
    public static function server(Server $server, ?User $user, ?ServerTask $after = null): Collection
    {
        // The whole lists as they are when the tasks run (E-090).
        $previous = $after;
        if ($server->firewallRules()->exists()) {
            $previous = ServerTask::start($server, ScriptCall::firewallRules([]), $user, after: $previous);
        }
        if ($server->fail2banIgnores()->exists()) {
            ServerTask::start($server, ScriptCall::fail2banIgnore([]), $user, after: $previous);
        }

        $sites = self::missingSites($server);
        $sites->each(fn (Site $site) => self::site($site, ScriptCall::LATEST, $user, $after));

        Audit::record('server.rebuild_started', $server, [
            'server' => $server->getKey(),
            'sites' => $sites->count(),
        ], $user);

        return $sites;
    }

    /**
     * Active websites of the server that it does not have (E-106).
     *
     * @return Collection<int, Site>
     */
    public static function missingSites(Server $server): Collection
    {
        return $server->sites()->with('server')->where('status', SiteStatus::Active->value)->orderBy('domain')->get()
            ->filter(fn (Site $site) => $site->missingOnServer())
            ->values();
    }
}
