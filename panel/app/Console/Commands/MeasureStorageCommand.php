<?php

namespace App\Console\Commands;

use App\Jobs\MeasureStorage;
use App\Models\Server;
use Illuminate\Console\Command;

/**
 * Starts measuring the storage on every server with websites (E-126).
 * Runs every 15 minutes.
 */
class MeasureStorageCommand extends Command
{
    protected $signature = 'owpanel:storage';

    protected $description = 'Measure the storage of all websites and adjust their quotas';

    public function handle(): int
    {
        Server::whereHas('sites')->each(fn (Server $server) => MeasureStorage::dispatch($server->getKey()));

        return self::SUCCESS;
    }
}
