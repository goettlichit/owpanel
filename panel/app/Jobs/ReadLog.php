<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Servers\LiveAnswers;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use App\Servers\ServerConnectionException;
use App\Sites\SiteDeploy;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads the end of a website log (site-logs, E-086) or a server log
 * (server-logs, E-087) in the query worker; the web request waits for the
 * answer (App\Servers\LiveAnswers). Logs hold personal data such as IP
 * addresses, so the answer is encrypted and the panel stores nothing.
 */
class ReadLog implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const SITE = 'site';

    public const SERVER = 'server';

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * The scripts keep their answer below about 4 MB (1000 lines of 4 KB).
     */
    private const MAX_OUTPUT_BYTES = 6 * 1024 * 1024;

    public function __construct(
        public readonly string $answerId,
        public readonly string $target,
        public readonly string $targetId,
        public readonly string $log,
        public readonly int $lines,
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
        if ($this->target === self::SITE) {
            $site = Site::with('server')->find($this->targetId);
            if ($site === null) {
                return ['error' => 'site_not_found'];
            }
            // The access log is written at the gateway, PHP and nginx errors
            // where the website runs (E-195).
            $server = $this->log === 'access' ? SiteDeploy::gatewayServer($site) : $site->server;
            $call = ScriptCall::siteLogs($site->system_user, $this->log, $this->lines);
        } else {
            $server = Server::find($this->targetId);
            if ($server === null) {
                return ['error' => 'server_not_found'];
            }
            $call = ScriptCall::serverLogs($this->log, $this->lines);
        }

        $output = '';
        $tooLarge = false;
        try {
            $result = $runner->run($server, $call, function (string $chunk) use (&$output, &$tooLarge): void {
                if (strlen($output) + strlen($chunk) > self::MAX_OUTPUT_BYTES) {
                    $tooLarge = true;

                    return;
                }
                $output .= $chunk;
            });
        } catch (ServerConnectionException $e) {
            return ['error' => $e->errorCode];
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'query_failed'];
        }

        if ($tooLarge) {
            return ['error' => 'result_too_large'];
        }

        $parsed = ScriptResult::fromOutput($call->script->value, $output, $result->exitCode, $result->timedOut);
        if (! $parsed->succeeded()) {
            return ['error' => $parsed->error() ?? 'invalid_result'];
        }

        $json = $parsed->json ?? [];
        $lines = $json['lines'] ?? null;
        if (! is_array($lines) || ! array_is_list($lines) || ! array_all($lines, fn (mixed $line): bool => is_string($line))) {
            return ['error' => 'invalid_result'];
        }

        return [
            'lines' => $lines,
            'size' => is_int($json['size'] ?? null) ? $json['size'] : null,
            'modified' => is_int($json['modified'] ?? null) ? $json['modified'] : null,
        ];
    }

    /**
     * Dispatches the job and waits for its answer.
     *
     * @return array<string, mixed>
     */
    public static function await(string $target, string $targetId, string $log, int $lines): array
    {
        $id = bin2hex(random_bytes(16));
        self::dispatch($id, $target, $targetId, $log, $lines);

        return LiveAnswers::wait($id) ?? ['error' => 'query_worker_timeout'];
    }
}
