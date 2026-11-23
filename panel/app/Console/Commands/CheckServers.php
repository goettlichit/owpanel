<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Servers\MaintenanceCheck;
use App\Servers\StatusCheck;
use Illuminate\Console\Command;

/**
 * Asks every server for its state and load (E-075), and about once an hour
 * for updates and certificates (E-076). The scheduler runs this
 * once a minute as owpanel-worker, the only user that can read the panel's
 * SSH key.
 */
class CheckServers extends Command
{
    protected $signature = 'owpanel:check-servers';

    protected $description = 'Run server-status on every server and store state and load';

    public function handle(StatusCheck $check, MaintenanceCheck $maintenance): int
    {
        Server::orderBy('name')->each(function (Server $server) use ($check, $maintenance) {
            $check->run($server);

            // Only servers that just answered; an offline server is tried
            // again at its next due time.
            if ($server->check_error === null && MaintenanceCheck::isDue($server)) {
                $maintenance->run($server);
            }

            if ($this->output->isVerbose()) {
                $this->line(sprintf('%s: %s', $server->name, $server->check_error ?? 'ok'));
            }
        });

        return self::SUCCESS;
    }
}
