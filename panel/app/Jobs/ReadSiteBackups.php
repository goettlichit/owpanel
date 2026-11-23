<?php

namespace App\Jobs;

use App\Models\Site;
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
 * Reads the restic snapshots of a website (site-backups, E-096) in the
 * query worker, like ReadLog. The panel stores nothing of them.
 */
class ReadSiteBackups implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $answerId,
        public readonly string $siteId,
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
        $site = Site::with('server')->find($this->siteId);
        if ($site === null) {
            return ['error' => 'site_not_found'];
        }

        $call = ScriptCall::siteBackups($site->system_user);
        $output = '';
        try {
            $result = $runner->run($site->server, $call, function (string $chunk) use (&$output): void {
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

        return ['snapshots' => self::snapshots($parsed->json['snapshots'] ?? [])];
    }

    /**
     * The snapshot list of site-backups or panel-backups, with only
     * well-formed entries.
     *
     * @return list<array{id: string, time: ?string, size: ?int, added: ?int, kind: string}>
     */
    public static function snapshots(mixed $raw): array
    {
        $snapshots = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null) || ! preg_match('/^[0-9a-f]{8}$/', $entry['id'])) {
                continue;
            }
            $snapshots[] = [
                'id' => $entry['id'],
                'time' => is_string($entry['time'] ?? null) ? $entry['time'] : null,
                'size' => is_int($entry['size'] ?? null) ? $entry['size'] : null,
                'added' => is_int($entry['added'] ?? null) ? $entry['added'] : null,
                'kind' => in_array($entry['kind'] ?? null, ['schedule', 'manual', 'restore'], true) ? $entry['kind'] : 'schedule',
            ];
        }

        return $snapshots;
    }

    /**
     * @return array<string, mixed>
     */
    public static function await(string $siteId): array
    {
        $id = bin2hex(random_bytes(16));
        self::dispatch($id, $siteId);

        return LiveAnswers::wait($id) ?? ['error' => 'query_worker_timeout'];
    }
}
