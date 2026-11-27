<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Jobs\CollectSiteStats;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Starts the counting of visitors on every server with active websites
 * (E-148). Runs once an hour.
 */
class CountVisitors extends Command
{
    protected $signature = 'owpanel:site-stats';

    protected $description = 'Count visitors of all websites from their access logs';

    public function handle(): int
    {
        // The access logs of the websites are at their gateway (E-195).
        $panel = Server::panelHost();
        if ($panel !== null) {
            if (Site::where('status', SiteStatus::Active->value)->exists()) {
                CollectSiteStats::dispatch($panel->getKey());
            }

            return self::SUCCESS;
        }
        Server::whereHas('sites', fn ($query) => $query->where('status', SiteStatus::Active->value))
            ->each(fn (Server $server) => CollectSiteStats::dispatch($server->getKey()));

        return self::SUCCESS;
    }
}
