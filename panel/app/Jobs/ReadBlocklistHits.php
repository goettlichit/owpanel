<?php

namespace App\Jobs;

use App\Models\Server;
use App\Servers\LiveAnswers;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use App\Servers\ServerConnectionException;
use App\Support\IpNetwork;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads which addresses the blocklists of a server dropped how often
 * (server-blocklist --stats, E-170) in the query worker, like
 * ReadFail2ban. IP addresses are personal data: the answer is encrypted and
 * the panel stores nothing.
 */
class ReadBlocklistHits implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

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
     * @return array{addresses: int, packets: int, bytes: int, top: list<array{ip: string, packets: int, bytes: int, seen: int}>, counting: bool}|array{error: string}
     */
    private function read(ScriptRunner $runner): array
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            return ['error' => 'server_not_found'];
        }

        $call = ScriptCall::blocklistStats();
        $output = '';
        try {
            $result = $runner->run($server, $call, function (string $chunk) use (&$output): void {
                if (strlen($output) < 2 * 1024 * 1024) {
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

        $top = [];
        foreach (array_slice((array) ($parsed->json['top'] ?? []), 0, 1000) as $entry) {
            if (! is_array($entry) || ! is_string($entry[0] ?? null)) {
                continue;
            }
            $ip = IpNetwork::normalize($entry[0]);
            if ($ip === null || str_contains($ip, '/')) {
                continue;
            }
            $top[] = [
                'ip' => $ip,
                'packets' => max(0, (int) ($entry[1] ?? 0)),
                'bytes' => max(0, (int) ($entry[2] ?? 0)),
                'seen' => max(0, (int) ($entry[3] ?? 0)),
            ];
        }

        return [
            'addresses' => max(0, (int) ($parsed->json['addresses'] ?? 0)),
            'packets' => max(0, (int) ($parsed->json['packets'] ?? 0)),
            'bytes' => max(0, (int) ($parsed->json['bytes'] ?? 0)),
            'top' => $top,
            // False while the server has no hit sets yet (older layout).
            'counting' => ($parsed->json['counting'] ?? true) !== false,
        ];
    }

    /**
     * Dispatches the job and waits for its answer.
     *
     * @return array<string, mixed>
     */
    public static function await(string $serverId): array
    {
        $id = bin2hex(random_bytes(16));
        self::dispatch($id, $serverId);

        return LiveAnswers::wait($id) ?? ['error' => 'query_worker_timeout'];
    }
}
