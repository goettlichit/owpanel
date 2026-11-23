<?php

namespace App\Console\Commands;

use App\Backups\BackupTarget;
use App\Jobs\RebuildMissingSites;
use App\Models\Server;
use Illuminate\Console\Command;

/**
 * After the panel was restored on a new server (E-099): the own server is
 * a new machine and needs a new key for its subaccount. Starts the same
 * task as "Save and connect" under Server -> Backups. Called by the
 * installer; does nothing when backups are not set up. With
 * --rebuild-sites the websites of the server are built again from their
 * backups once it is connected (E-106).
 */
class ReconnectBackups extends Command
{
    protected $signature = 'owpanel:reconnect-backups {--rebuild-sites : Also rebuild the websites the server lacks}';

    protected $description = 'Connect the backups of the panel server again after a restore';

    public function handle(): int
    {
        $server = Server::panelHost();
        $task = null;
        if ($server === null || $server->backup_ready || ! $server->backupConfigured()) {
            $this->line('nothing to reconnect');
        } else {
            $task = BackupTarget::setUp($server, null);
            $this->line($task === null ? 'nothing to reconnect' : "backup setup started for {$server->name}");
        }

        if ($this->option('rebuild-sites')) {
            if ($server === null || ($task === null && ! $server->backupActive())) {
                $this->line('websites not rebuilt: no backup target');

                return self::SUCCESS;
            }
            // In the worker, after the task above: only it can reach the server.
            RebuildMissingSites::dispatch($server->getKey(), $task?->getKey());
            $this->line("rebuild of the websites of {$server->name} queued");
        }

        return self::SUCCESS;
    }
}
