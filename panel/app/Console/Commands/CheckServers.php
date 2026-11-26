<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Servers\MaintenanceCheck;
use App\Servers\StatusCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Concurrency;
use Throwable;

/**
 * Asks every server for its state and load (E-075), and about once an hour
 * for updates and certificates (E-076). The scheduler runs this once a
 * minute as owpanel-worker, the only user that can read the panel's SSH
 * key.
 *
 * Each server is checked in its own process, up to ten side by side (M5-1),
 * so a server that does not answer (10 seconds connection timeout) holds
 * up no other one and the minute is enough for many servers. The tests
 * run the same code without processes (CONCURRENCY_DRIVER=sync).
 */
class CheckServers extends Command
{
    /** Processes at the same time. */
    public const PARALLEL = 10;

    protected $signature = 'owpanel:check-servers';

    protected $description = 'Run server-status on every server and store state and load';

    public function handle(): int
    {
        $ids = Server::orderBy('name')->pluck('id')->all();

        foreach (array_chunk($ids, self::PARALLEL) as $chunk) {
            try {
                $lines = Concurrency::run(self::tasks($chunk));
            } catch (Throwable $e) {
                // A process that broke off; the others have stored their result.
                report($e);
                $lines = [];
            }

            if ($this->output->isVerbose()) {
                foreach ($lines as $line) {
                    $this->line($line);
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * One closure per server for the child processes. Each closure stands
     * on its own line: the closure serializer reads the source code and
     * takes the first closure of a line, so a closure that returns a
     * closure on the same line was sent as the outer one (E-191, fix).
     *
     * @param  list<string>  $ids
     * @return array<string, \Closure(): string>
     */
    public static function tasks(array $ids): array
    {
        $tasks = [];
        foreach ($ids as $id) {
            $tasks[$id] = static function () use ($id): string {
                return CheckServers::checkOne($id);
            };
        }

        return $tasks;
    }

    /**
     * Checks one server; runs in its own process.
     */
    public static function checkOne(string $id): string
    {
        $server = Server::find($id);
        if ($server === null) {
            return "{$id}: gone";
        }

        try {
            app(StatusCheck::class)->run($server);

            // Only servers that just answered; an offline server is tried
            // again at its next due time.
            if ($server->check_error === null && MaintenanceCheck::isDue($server)) {
                app(MaintenanceCheck::class)->run($server);
            }
        } catch (Throwable $e) {
            report($e);

            return "{$server->name}: error";
        }

        return sprintf('%s: %s', $server->name, $server->check_error ?? 'ok');
    }
}
