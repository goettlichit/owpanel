<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Models\Server;
use App\Models\ServerTask;
use App\Servers\ScriptCall;
use Illuminate\Console\Command;

/**
 * Writes the configuration of every website of the panel server again, so
 * new templates of an update reach websites created before (E-171), such as
 * the placeholder page. Called by the installer on every run that is not a
 * first installation or a restore. Without --tls no certificate is requested;
 * a website keeps HTTPS while its certificate covers it. --existing leaves
 * out websites whose files are missing, which are rebuilt from their backup
 * instead. Suspended websites and those with open tasks are left alone.
 */
class RefreshSites extends Command
{
    protected $signature = 'owpanel:refresh-sites';

    protected $description = 'Write the configuration of all websites of the panel server again';

    public function handle(): int
    {
        $server = Server::panelHost();
        if ($server === null) {
            $this->line('no panel server');

            return self::SUCCESS;
        }

        $count = 0;
        $server->sites()->where('status', SiteStatus::Active->value)->whereNull('suspended_at')
            ->orderBy('domain')->get()
            ->reject(fn ($site) => $site->hasOpenTasks())
            ->each(function ($site) use ($server, &$count) {
                ServerTask::start($server, ScriptCall::siteCreate($site->domain, $site->system_user, false, $site->www, existingOnly: true), null, $site);
                $count++;
            });
        $this->line("{$count} websites queued");

        return self::SUCCESS;
    }
}
