<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Models\Server;
use App\Models\Site;
use App\Sites\SiteDeploy;
use Illuminate\Console\Command;

/**
 * Writes the configuration of every website again, backend and gateway
 * (E-195), so new templates of an update reach websites created before
 * (E-171), such as the placeholder page. Called by the installer on every
 * run that is not a first installation or a restore. No certificate is
 * requested; a website keeps HTTPS while its certificate covers it.
 * --existing leaves out websites whose files are missing, which are rebuilt
 * from their backup instead. Suspended websites get only their gateway;
 * websites with open tasks are left alone.
 */
class RefreshSites extends Command
{
    protected $signature = 'owpanel:refresh-sites';

    protected $description = 'Write the configuration of all websites again';

    public function handle(): int
    {
        if (Server::panelHost() === null) {
            $this->line('no panel server');

            return self::SUCCESS;
        }

        // Every website has its gateway on the panel server (E-195), so all
        // of them are written again, not only those on this server.
        $count = 0;
        Site::query()->where('status', SiteStatus::Active->value)
            ->with('server')->orderBy('domain')->get()
            ->reject(fn (Site $site) => $site->hasOpenTasks())
            ->each(function (Site $site) use (&$count) {
                // A suspended website keeps its resting backend; only its
                // gateway with the suspension page is written.
                $site->isSuspended()
                    ? SiteDeploy::gatewayOnly($site, null)
                    : SiteDeploy::configure($site, null, false, existingOnly: true);
                $count++;
            });
        $this->line("{$count} websites queued");

        return self::SUCCESS;
    }
}
