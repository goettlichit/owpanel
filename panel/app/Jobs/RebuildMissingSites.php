<?php

namespace App\Jobs;

use App\Backups\Rebuild;
use App\Models\Server;
use App\Models\ServerTask;
use App\Servers\StatusCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * After the panel was restored on a new server, the installer can also
 * build the websites of that server again (E-106). Runs in the queue
 * worker, which holds the SSH key: it asks the server which websites it
 * has, then starts the rebuild of the missing ones. Their tasks wait for
 * the reconnection of the backups, if one was started.
 */
class RebuildMissingSites implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $serverId, public readonly ?string $afterTaskId = null) {}

    public function handle(StatusCheck $check): void
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            return;
        }

        $check->run($server);
        $server->refresh();
        if ($server->check_error !== null || $server->present_sites === null) {
            Log::warning('Rebuild after restore: the server could not be checked; nothing started.', [
                'server' => $server->getKey(),
                'error' => $server->check_error,
            ]);

            return;
        }

        $after = $this->afterTaskId === null ? null : ServerTask::find($this->afterTaskId);
        $sites = Rebuild::server($server, null, $after);
        Log::info('Rebuild after restore started.', ['server' => $server->getKey(), 'sites' => $sites->count()]);
    }
}
