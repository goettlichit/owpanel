<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
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
 * Counts the visitors of all active websites of one server (E-148), once
 * an hour. Today and yesterday are counted again each time, so the last
 * hours of yesterday arrive after midnight; a website without numbers yet
 * gets the 14 days its rotated logs reach back. Runs on the queue of the
 * server tasks, without a task of its own: nothing changes on the server.
 */
class CollectSiteStats implements ShouldQueue
{
    use Queueable;

    /** Output of one website: 14 days with ten paths each, well below. */
    private const MAX_OUTPUT_BYTES = 256 * 1024;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $serverId) {}

    public function handle(ScriptRunner $runner): void
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            return;
        }

        // The access logs of the websites are written at their gateway (E-195).
        $server->gatewaySites()?->where('status', SiteStatus::Active->value)->orderBy('domain')->get()
            ->each(fn (Site $site) => $this->collect($runner, $server, $site));
    }

    private function collect(ScriptRunner $runner, Server $server, Site $site): void
    {
        $call = ScriptCall::siteStats($site->system_user, $site->visits()->exists() ? 2 : 14);
        $output = '';
        try {
            $result = $runner->run($server, $call, function (string $chunk) use (&$output): void {
                if (strlen($output) < self::MAX_OUTPUT_BYTES) {
                    $output .= $chunk;
                }
            });
        } catch (ServerConnectionException) {
            // The server checks report an unreachable server already.
            return;
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $parsed = ScriptResult::fromOutput($call->script->value, $output, $result->exitCode, $result->timedOut);
        $days = $parsed->json['days'] ?? null;
        if (! $parsed->succeeded() || ! is_array($days)) {
            Log::warning('Visitors could not be counted', ['site' => $site->getKey(), 'error' => $parsed->error() ?? 'invalid_result']);

            return;
        }

        foreach ($days as $day) {
            if (! self::validDay($day)) {
                continue;
            }
            $site->visits()->updateOrCreate(['date' => $day['date']], [
                'visitors' => $day['visitors'],
                'pageviews' => $day['pageviews'],
                'pages' => array_map(fn (array $page) => [mb_substr($page[0], 0, 200), $page[1]], array_slice($day['pages'], 0, 10)),
            ]);
        }
    }

    private static function validDay(mixed $day): bool
    {
        return is_array($day)
            && is_string($day['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day['date']) === 1
            && is_int($day['visitors'] ?? null) && $day['visitors'] >= 0
            && is_int($day['pageviews'] ?? null) && $day['pageviews'] >= 0
            && is_array($day['pages'] ?? null)
            && array_all($day['pages'], fn (mixed $page) => is_array($page) && is_string($page[0] ?? null) && is_int($page[1] ?? null));
    }
}
