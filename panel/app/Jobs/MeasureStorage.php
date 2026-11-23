<?php

namespace App\Jobs;

use App\Customers\StorageQuota;
use App\Models\Server;
use App\Models\Site;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use App\Servers\ServerConnectionException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Measures the storage of all websites of one server every 15 minutes and
 * adjusts the hard limits of their files (E-126). Runs on the queue of the
 * server tasks without a task of its own; a limit is set only when it
 * changes, and never while a task runs for the website, e.g. a restore.
 */
class MeasureStorage implements ShouldQueue
{
    use Queueable;

    private const MAX_OUTPUT_BYTES = 1024 * 1024;

    /** Changes below 1 MiB are not worth a call. */
    private const QUOTA_STEP_KIB = 1024;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $serverId) {}

    public function handle(ScriptRunner $runner): void
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            return;
        }

        $result = $this->run($runner, $server, ScriptCall::siteUsage());
        if ($result === null || ! is_array($result->json['sites'] ?? null)) {
            return;
        }

        $server->forceFill([
            'quota_active' => ($result->json['quota'] ?? false) === true,
            'storage_filesystem' => is_string($result->json['filesystem'] ?? null) ? substr($result->json['filesystem'], 0, 20) : null,
        ])->save();

        $limits = [];
        foreach ($result->json['sites'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['user'] ?? null)
                || ! is_int($entry['files'] ?? null) || ! is_int($entry['databases'] ?? null) || ! is_int($entry['limit'] ?? null)) {
                continue;
            }
            $updated = $server->sites()->where('system_user', $entry['user'])->update([
                'usage_files_bytes' => max(0, $entry['files']),
                'usage_db_bytes' => max(0, $entry['databases']),
                'usage_measured_at' => now(),
            ]);
            if ($updated > 0) {
                $limits[$entry['user']] = intdiv(max(0, $entry['limit']), 1024);
            }
        }

        if ($server->quota_active) {
            $this->applyQuotas($runner, $server, $limits);
        }
    }

    /**
     * @param  array<string, int>  $limits  Hard limits in KiB as the server has them now.
     */
    private function applyQuotas(ScriptRunner $runner, Server $server, array $limits): void
    {
        $server->sites()->with('customer.package')->get()->each(function (Site $site) use ($runner, $server, $limits): void {
            if (! array_key_exists($site->system_user, $limits) || $site->isBusy()) {
                return;
            }
            $target = StorageQuota::targetKib($site);
            $current = $limits[$site->system_user];
            if ($target === $current || ($target > 0 && $current > 0 && abs($target - $current) < self::QUOTA_STEP_KIB)) {
                $site->forceFill(['quota_kib' => $current])->save();

                return;
            }
            if ($this->run($runner, $server, ScriptCall::siteQuota($site->system_user, $target)) !== null) {
                $site->forceFill(['quota_kib' => $target])->save();
            }
        });
    }

    private function run(ScriptRunner $runner, Server $server, ScriptCall $call): ?ScriptResult
    {
        $output = '';
        try {
            $result = $runner->run($server, $call, function (string $chunk) use (&$output): void {
                if (strlen($output) < self::MAX_OUTPUT_BYTES) {
                    $output .= $chunk;
                }
            });
        } catch (ServerConnectionException) {
            // The server checks report an unreachable server already.
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $parsed = ScriptResult::fromOutput($call->script->value, $output, $result->exitCode, $result->timedOut);
        if (! $parsed->succeeded()) {
            Log::warning('Storage script failed', ['server' => $server->getKey(), 'script' => $call->script->value, 'error' => $parsed->error()]);

            return null;
        }

        return $parsed;
    }
}
