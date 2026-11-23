<?php

namespace App\Notify;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\ServerSnapshot;
use App\Models\ServerTask;
use Carbon\CarbonInterface;

/**
 * One-off messages about things that happened (E-104): finished backups,
 * snapshots and restores, failed tasks, restarts and installed updates.
 * Called where the panel learns about them; never throws, so a message
 * can never break the work it reports on.
 */
final class Events
{
    /** Scripts that only read; their failures show where they are used. */
    private const QUIET_SCRIPTS = [
        ServerScript::ServerStatus, ServerScript::ServerUpdates, ServerScript::ServerLogs, ServerScript::SiteLogs,
        ServerScript::DbQuery, ServerScript::PanelTest, ServerScript::SiteBackups, ServerScript::SiteBackupFiles,
        ServerScript::PanelBackups, ServerScript::PanelBackupFiles,
    ];

    public static function taskFinished(ServerTask $task): void
    {
        self::safely(function () use ($task) {
            $manual = in_array('--manual', (array) $task->arguments, true);
            $backup = in_array($task->script, [ServerScript::SiteBackup, ServerScript::PanelBackup], true);
            $server = $task->server;

            if ($task->status === TaskStatus::Failed) {
                // Scheduled backups have their own alert with all-clear.
                if (($backup && ! $manual) || in_array($task->script, self::QUIET_SCRIPTS, true)) {
                    return;
                }
                Notifier::notify(NotificationEvent::TaskFailed, "task:{$task->getKey()}", [
                    'server' => $server->name,
                    'task' => __('tasks.scripts.'.$task->script->value),
                    'site' => (string) ($task->site?->domain ?? ''),
                    'error' => (string) $task->errorMessage(),
                ], route('tasks.show', $task));

                return;
            }

            if ($task->status !== TaskStatus::Succeeded) {
                return;
            }

            if ($task->script === ServerScript::SiteRestore) {
                Notifier::notify(NotificationEvent::RestoreFinished, "restore:{$task->getKey()}", [
                    'server' => $server->name,
                    'site' => (string) ($task->site?->domain ?? ''),
                    'duration' => self::duration($task->started_at, $task->finished_at),
                ], route('tasks.show', $task));

                return;
            }

            if ($backup && $manual) {
                Notifier::notify(NotificationEvent::BackupSucceeded, "backup:{$task->getKey()}", [
                    'server' => $server->name,
                    'items' => $task->site?->domain ?? __('notifications.panel'),
                    'duration' => self::duration($task->started_at, $task->finished_at),
                ], route('tasks.show', $task));

                return;
            }

            if ($backup) {
                self::scheduledBackupsDone($task);
            }
        });
    }

    /**
     * One message when the last scheduled backup of a run has finished and
     * all of them succeeded; failures have their own alert. A run is the
     * batch of tasks RunBackups started together. A backup without batch,
     * e.g. the panel's first one when the Storage Box is connected, is a
     * run of its own.
     */
    private static function scheduledBackupsDone(ServerTask $finished): void
    {
        $server = $finished->server;
        $tasks = $finished->batch === null
            ? collect([$finished])
            : $server->tasks()->with('site')->where('batch', $finished->batch)->get();

        if ($tasks->contains(fn (ServerTask $task) => ! $task->isFinished())
            || $tasks->contains(fn (ServerTask $task) => $task->status !== TaskStatus::Succeeded)) {
            return;
        }

        Notifier::notify(NotificationEvent::BackupSucceeded, 'backups:'.($finished->batch ?? $finished->getKey()), [
            'server' => $server->name,
            'items' => $tasks->map(fn (ServerTask $task) => $task->site?->domain ?? __('notifications.panel'))->unique()->sort()->implode(', '),
            'duration' => self::duration($tasks->min('started_at'), $tasks->max('finished_at')),
        ], route('servers.backups', $server));
    }

    public static function snapshotDone(ServerSnapshot $run): void
    {
        self::safely(fn () => Notifier::notify(NotificationEvent::SnapshotSucceeded, "snapshot:{$run->getKey()}", [
            'server' => (string) $run->server?->name,
            'duration' => self::duration($run->created_at, $run->finished_at),
        ], $run->server ? route('servers.snapshots', $run->server) : null));
    }

    public static function serverRestarted(Server $server, CarbonInterface $bootedAt): void
    {
        self::safely(fn () => Notifier::notify(NotificationEvent::ServerRestarted, "restarted:{$server->getKey()}", [
            'server' => $server->name,
            'time' => $bootedAt->copy()->setTimezone((string) config('owpanel.timezone'))->isoFormat('LT'),
            'kernel' => (string) $server->kernel,
        ], route('servers.show', $server)));
    }

    /**
     * @param  array<string, mixed>  $run  newest entry of the update history
     */
    public static function updatesInstalled(Server $server, array $run): void
    {
        self::safely(function () use ($server, $run) {
            $packages = array_slice((array) ($run['packages'] ?? []), 0, 8);
            $more = (int) ($run['count'] ?? 0) - count($packages);
            Notifier::notify(NotificationEvent::UpdatesInstalled, "updates:{$server->getKey()}", [
                'server' => $server->name,
                'count' => (int) ($run['count'] ?? 0),
                'kind' => __('notifications.update_kinds.'.(($run['kind'] ?? '') === 'manual' ? 'manual' : 'automatic')),
                'packages' => implode(', ', $packages).($more > 0 ? ' …' : ''),
            ], route('servers.show', $server));
        });
    }

    private static function duration(mixed $from, mixed $to): string
    {
        if (! $from instanceof CarbonInterface || ! $to instanceof CarbonInterface) {
            return '–';
        }
        $seconds = max(0, (int) $from->diffInSeconds($to, true));

        return $seconds < 60
            ? __('notifications.duration.seconds', ['count' => $seconds])
            : __('notifications.duration.minutes', ['count' => (int) ceil($seconds / 60)]);
    }

    private static function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
