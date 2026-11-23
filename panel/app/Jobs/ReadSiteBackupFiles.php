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
 * Reads one directory and the database dumps of a website's snapshot
 * (site-backup-files, E-098) in the query worker. Nothing is stored.
 */
class ReadSiteBackupFiles implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $answerId,
        public readonly string $siteId,
        public readonly string $snapshot,
        public readonly string $path,
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

        $call = ScriptCall::siteBackupFiles($site->system_user, $this->snapshot, $this->path);
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

        return [
            'files' => self::entries($parsed->json['files']['entries'] ?? []),
            'more' => ($parsed->json['files']['more'] ?? false) === true,
            'databases' => array_values(array_map(
                fn (array $entry) => ['name' => substr($entry['name'], 0, -4), 'size' => $entry['size']],
                array_filter(self::entries($parsed->json['dumps']['entries'] ?? []),
                    fn (array $entry) => $entry['type'] === 'file' && preg_match('/^'.preg_quote($site->system_user, '/').'_[a-z0-9][a-z0-9_]{0,15}\.sql$/', $entry['name']) === 1),
            )),
        ];
    }

    /**
     * Only entries with a plain name and known fields.
     *
     * @return list<array{name: string, type: string, size: ?int, mtime: ?string}>
     */
    private static function entries(mixed $raw): array
    {
        $entries = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || $entry['name'] === '' || str_contains($entry['name'], '/')
                || $entry['name'] === '.' || $entry['name'] === '..' || preg_match('/[\x00-\x1f\x7f]/', $entry['name'])) {
                continue;
            }
            $entries[] = [
                'name' => $entry['name'],
                'type' => in_array($entry['type'] ?? null, ['dir', 'file', 'symlink'], true) ? $entry['type'] : 'other',
                'size' => is_int($entry['size'] ?? null) ? $entry['size'] : null,
                'mtime' => is_string($entry['mtime'] ?? null) ? $entry['mtime'] : null,
            ];
        }

        return array_slice($entries, 0, 500);
    }

    /**
     * @return array<string, mixed>
     */
    public static function await(string $siteId, string $snapshot, string $path = ''): array
    {
        $id = bin2hex(random_bytes(16));
        self::dispatch($id, $siteId, $snapshot, $path);

        return LiveAnswers::wait($id) ?? ['error' => 'query_worker_timeout'];
    }
}
