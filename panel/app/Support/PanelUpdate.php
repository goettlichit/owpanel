<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Setting;
use App\Models\User;
use App\Notify\Events;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use App\Servers\ServerConnectionException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Updates the panel to its newest release (E-185), by hand or by itself at
 * night.
 *
 * A task runs panel-update on the panel's own server. The script checks
 * the release and starts the installer in the background, then returns;
 * the installer restarts the worker and the scheduler. owpanel:panel-update
 * then follows the update once a minute: it is done once the panel runs
 * the new version, and failed when panel-update --status says so.
 *
 * The state lives in the setting panel_update as JSON:
 *   state      starting (task queued or running), running (installer),
 *              succeeded, failed, postponed (automatic update skipped)
 *   version    target, from: installed version before
 *   auto       automatic update or by hand
 *   task       id of the task that ran panel-update
 *   started_at, finished_at (ISO 8601), error (code from tasks.errors)
 */
final class PanelUpdate
{
    /** The installer usually needs a few minutes; after this it failed. */
    public const TIMEOUT_MINUTES = 60;

    /** Automatic updates wait this long after a release (E-185). */
    public const AUTO_DELAY_HOURS = 24;

    private const STATES = ['starting', 'running', 'succeeded', 'failed', 'postponed'];

    /**
     * @return array{state: string|null, version: string|null, from: string|null, auto: bool, task: string|null, started_at: Carbon|null, finished_at: Carbon|null, error: string|null}
     */
    public static function current(): array
    {
        $data = json_decode((string) Setting::get(Setting::PANEL_UPDATE), true);
        $data = is_array($data) ? $data : [];
        $time = function (mixed $value): ?Carbon {
            try {
                return is_string($value) ? Carbon::parse($value) : null;
            } catch (Throwable) {
                return null;
            }
        };
        $string = fn (string $key, string $pattern) => is_string($data[$key] ?? null) && preg_match($pattern, $data[$key]) ? $data[$key] : null;

        return [
            'state' => in_array($data['state'] ?? null, self::STATES, true) ? $data['state'] : null,
            'version' => $string('version', '/^[0-9A-Za-z.-]{1,64}$/'),
            'from' => $string('from', '/^[0-9A-Za-z.-]{1,64}$/'),
            'auto' => ($data['auto'] ?? false) === true,
            'task' => $string('task', '/^[0-9A-Za-z-]{1,64}$/'),
            'started_at' => $time($data['started_at'] ?? null),
            'finished_at' => $time($data['finished_at'] ?? null),
            'error' => $string('error', '/^[a-z0-9_]{1,64}$/'),
        ];
    }

    public static function inProgress(): bool
    {
        return in_array(self::current()['state'], ['starting', 'running'], true);
    }

    public static function autoEnabled(): bool
    {
        return Setting::get(Setting::PANEL_AUTO_UPDATE) === '1';
    }

    public static function setAuto(bool $enabled): void
    {
        Setting::put(Setting::PANEL_AUTO_UPDATE, $enabled ? '1' : '0');
    }

    /**
     * Starts the update to the newest release. Returns an error code from
     * servers.updates.panel.refused, or null when the task was started.
     */
    public static function start(?User $user, bool $auto = false): ?string
    {
        $panel = Server::panelHost();
        $version = PanelRelease::latest()['version'];
        if ($panel === null) {
            return 'no_panel_server';
        }
        if (self::inProgress()) {
            return 'running';
        }
        if ($version === null || ! PanelRelease::updateAvailable()) {
            return 'not_available';
        }

        $task = ServerTask::start($panel, ScriptCall::panelUpdate($version), $user);
        self::write([
            'state' => 'starting',
            'version' => $version,
            'from' => PanelRelease::installed(),
            'auto' => $auto,
            'task' => $task->getKey(),
            'started_at' => now()->toIso8601String(),
        ]);
        Audit::record('server.panel_update_started', $panel, [
            'version' => $version,
            'from' => PanelRelease::installed(),
            'auto' => $auto,
        ], $user);

        return null;
    }

    /**
     * The nightly automatic update (E-185): only when switched on, only a
     * patch version of the installed release that is at least a day old.
     * While tasks wait or run, it waits for the next night.
     */
    public static function runAutomatic(): void
    {
        if (! self::autoEnabled() || self::inProgress() || ! PanelRelease::updateAvailable()) {
            return;
        }
        $latest = PanelRelease::latest();
        $version = (string) $latest['version'];
        if (! PanelRelease::isPatchOf($version, PanelRelease::installed())) {
            return;
        }
        $published = $latest['published_at'] !== null ? Carbon::parse($latest['published_at']) : null;
        if ($published === null || $published->gt(now()->subHours(self::AUTO_DELAY_HOURS))) {
            return;
        }

        $busy = ServerTask::whereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value])->exists();
        if ($busy) {
            self::write([
                'state' => 'postponed',
                'version' => $version,
                'from' => PanelRelease::installed(),
                'auto' => true,
                'started_at' => now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'error' => 'tasks_running',
            ]);

            return;
        }

        self::start(null, true);
    }

    /**
     * Follows a running update; called once a minute by the scheduler.
     */
    public static function follow(ScriptRunner $runner): void
    {
        $update = self::current();
        if (! in_array($update['state'], ['starting', 'running'], true)) {
            return;
        }

        // The new release runs, but the installer may still be at work
        // (websites, release check). Done once panel-update says the run
        // ended; the scheduler runs each minute with the current release.
        $switched = $update['version'] !== null && PanelRelease::installed() === $update['version'];

        if ($update['state'] === 'starting') {
            $task = $update['task'] !== null ? ServerTask::find($update['task']) : null;
            if ($task === null) {
                self::finish($update, 'failed', 'task_missing', notify: false);

                return;
            }
            if ($task->status === TaskStatus::Failed) {
                // The failed task has already sent its own message.
                self::finish($update, 'failed', $task->error ?? 'script_failed', notify: false);

                return;
            }
            if ($task->status === TaskStatus::Succeeded || $switched) {
                self::write(['state' => 'running'] + self::raw());
                $update['state'] = 'running';
            }
        }

        if ($update['state'] === 'running') {
            $status = self::readStatus($runner);
            $mine = ($status['version'] ?? null) === $update['version'];
            if ($mine && ($status['state'] ?? null) === 'succeeded' && $switched) {
                self::finish($update, 'succeeded', null);

                return;
            }
            if ($mine && ($status['state'] ?? null) === 'failed') {
                self::finish($update, 'failed', is_string($status['error'] ?? null) ? $status['error'] : 'install_failed');

                return;
            }
            // The installer ended well, but this panel does not report the
            // new version: another installation ran over it since (e.g.
            // get-owpanel by hand). The update itself worked.
            if ($mine && ($status['state'] ?? null) === 'succeeded' && is_string($status['finished_at'] ?? null)
                && Carbon::parse((string) $status['finished_at'])->lt(now()->subMinutes(5))) {
                self::finish($update, 'succeeded', null);

                return;
            }
        }

        if ($update['started_at'] !== null && $update['started_at']->lt(now()->subMinutes(self::TIMEOUT_MINUTES))) {
            // The state could not be read, but the new release runs.
            $switched ? self::finish($update, 'succeeded', null) : self::finish($update, 'failed', 'update_timeout');
        }
    }

    /**
     * panel-update --status on the panel's server; empty when it cannot be
     * read, e.g. while the installer restarts SSH.
     *
     * @return array<string, mixed>
     */
    private static function readStatus(ScriptRunner $runner): array
    {
        $panel = Server::panelHost();
        if ($panel === null) {
            return [];
        }
        $call = ScriptCall::panelUpdateStatus();
        $output = '';
        try {
            $result = $runner->run($panel, $call, function (string $chunk) use (&$output): void {
                if (strlen($output) < 64 * 1024) {
                    $output .= $chunk;
                }
            });
        } catch (ServerConnectionException) {
            return [];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
        $parsed = ScriptResult::fromOutput($call->script->value, $output, $result->exitCode, $result->timedOut);

        return $parsed->succeeded() ? (array) $parsed->json : [];
    }

    /**
     * @param  array<string, mixed>  $update  as from current()
     */
    private static function finish(array $update, string $state, ?string $error, bool $notify = true): void
    {
        self::write(['state' => $state, 'finished_at' => now()->toIso8601String(), 'error' => $error] + self::raw());
        $panel = Server::panelHost();
        if ($panel !== null) {
            Audit::record($state === 'succeeded' ? 'server.panel_updated' : 'server.panel_update_failed', $panel, array_filter([
                'version' => $update['version'],
                'from' => $update['from'],
                'auto' => $update['auto'],
                'error' => $error,
            ], fn ($value) => $value !== null));
        }
        // The newest release as of now, so the page shows no older one.
        if ($state === 'succeeded') {
            PanelRelease::check();
        }
        if ($notify) {
            Events::panelUpdateFinished($state === 'succeeded', (string) $update['version'], (string) $update['from'], $error);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function raw(): array
    {
        $data = json_decode((string) Setting::get(Setting::PANEL_UPDATE), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function write(array $data): void
    {
        Setting::put(Setting::PANEL_UPDATE, (string) json_encode($data));
    }
}
