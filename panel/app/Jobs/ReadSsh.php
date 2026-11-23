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
 * Reads the SSH login state of a server (server-ssh --status, E-155) in
 * the query worker, like ReadFail2ban: password login, which login
 * accounts have a key, and whether AllowUsers or AllowGroups keep SFTP
 * customers out. The panel stores nothing.
 */
class ReadSsh implements ShouldBeEncrypted, ShouldQueue
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

        $call = ScriptCall::sshStatus();
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

        $accounts = [];
        foreach ((array) ($parsed->json['accounts'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['user'] ?? null)) {
                $accounts[] = ['user' => $entry['user'], 'key' => ($entry['key'] ?? false) === true];
            }
        }

        return [
            'password_login' => ($parsed->json['password_login'] ?? true) === true,
            'managed' => ($parsed->json['managed'] ?? false) === true,
            'accounts' => $accounts,
            'allow_users' => is_string($parsed->json['allow_users'] ?? null) ? $parsed->json['allow_users'] : '',
            'allow_groups' => is_string($parsed->json['allow_groups'] ?? null) ? $parsed->json['allow_groups'] : '',
            'sftp_allowed' => ($parsed->json['sftp_allowed'] ?? true) === true,
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
