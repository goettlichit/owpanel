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
        // The access logs of all websites are at the gateway on the panel
        // server (E-195).
        $server = Server::panelHost();
        if ($server !== null && Site::where('status', SiteStatus::Active->value)->exists()) {
            CollectSiteStats::dispatch($server->getKey());
        }

        return self::SUCCESS;
    }
}
