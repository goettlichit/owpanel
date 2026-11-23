<?php

namespace App\Jobs;

use App\Models\Server;
use App\Servers\LiveAnswers;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use App\Servers\ServerConnectionException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads the backups of the panel itself (panel-backups, E-099) from the
 * server it runs on, in the query worker. Nothing is stored.
 */
class ReadPanelBackups implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $answerId,
        public readonly string $serverId,
    ) {
        $this->onQueue(RunDatabaseQuery::QUEUE);
    }

    public function handle(ScriptRunner $runner): void
    {
        LiveAnswers::put($this->answerId, $this->read($runner));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(ScriptRunner $runner): array
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            return ['error' => 'server_not_found'];
        }

        $call = ScriptCall::panelBackups();
        $output = '';
        try {
            $result = $runner->run($server, $call, function (string $chunk) use (&$output): void {
                if (strlen($output) < 1024 * 1024) {
                    $output .= $chunk;
                }
            });
        } catch (ServerConnectionException $e) {
            return ['error' => $e->errorCode];
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'query_failed'];
        }

        $parsed = ScriptResult::fromOutput($call->script->value, $output, $result->exitCode, $result->timedOut);
        if (! $parsed->succeeded()) {
            return ['error' => $parsed->error() ?? 'invalid_result'];
        }

        return ['snapshots' => ReadSiteBackups::snapshots($parsed->json['snapshots'] ?? [])];
    }

    /**
     * @return array<string, mixed>
     */
    public static function await(string $serverId): array
    {
        $id = bin2hex(random_bytes(16));
        self::dispatch($id, $serverId);

        return LiveAnswers::wait($id) ?? ['error' => 'query_worker_timeout'];
    }
}
