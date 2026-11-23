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
 * Reads the addresses fail2ban has banned on a server (server-fail2ban
 * --action status, E-090) in the query worker, like ReadLog. IP addresses
 * are personal data: the answer is encrypted and the panel stores nothing.
 */
class ReadFail2ban implements ShouldBeEncrypted, ShouldQueue
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
     * @return array<string, mixed>
     */
    private function read(ScriptRunner $runner): array
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            return ['error' => 'server_not_found'];
        }

        $call = ScriptCall::fail2banStatus();
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

        $banned = [];
        foreach ((array) ($parsed->json['banned'] ?? []) as $entry) {
            $ip = is_array($entry) && is_string($entry['ip'] ?? null) ? IpNetwork::normalize($entry['ip']) : null;
            if ($ip === null || str_contains($ip, '/')) {
                continue;
            }
            $banned[] = [
                'ip' => $ip,
                'jail' => is_string($entry['jail'] ?? null) ? $entry['jail'] : '',
                'until' => is_string($entry['until'] ?? null) ? $entry['until'] : null,
            ];
        }
        $ignore = array_values(array_filter(
            array_map(fn (mixed $address): ?string => is_string($address) ? IpNetwork::normalize($address) : null, (array) ($parsed->json['ignore'] ?? [])),
        ));

        return ['banned' => $banned, 'ignore' => $ignore];
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
