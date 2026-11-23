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
 * Reads the file list of one panel backup (panel-backup-files, E-099) in
 * the query worker. Paths, sizes and times only; nothing is stored.
 */
class ReadPanelBackupFiles implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $answerId,
        public readonly string $serverId,
        public readonly string $snapshot,
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

        $call = ScriptCall::panelBackupFiles($this->snapshot);
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

        $files = [];
        foreach ((array) ($parsed->json['files']['entries'] ?? []) as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || ! str_starts_with($entry['name'], '/')
                || preg_match('/[\x00-\x1f\x7f]/', $entry['name']) || str_contains($entry['name'], '/../')) {
                continue;
            }
            $files[] = [
                'path' => $entry['name'],
                'size' => is_int($entry['size'] ?? null) ? $entry['size'] : null,
                'mtime' => is_string($entry['mtime'] ?? null) ? $entry['mtime'] : null,
            ];
        }

        return ['files' => array_slice($files, 0, 200)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function await(string $serverId, string $snapshot): array
    {
        $id = bin2hex(random_bytes(16));
        self::dispatch($id, $serverId, $snapshot);

        return LiveAnswers::wait($id) ?? ['error' => 'query_worker_timeout'];
    }
}
