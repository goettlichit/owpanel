<?php

namespace App\Workers;

use App\Jobs\QueueHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * The background services of the panel under System -> Services (E-133):
 * the task worker (owpanel-queue), the query worker (owpanel-query) and the
 * scheduler (owpanel-scheduler.timer).
 *
 * Their state comes from the panel itself, without root: the scheduler
 * notes each run and puts a heartbeat job into each queue once a minute;
 * a worker that runs it notes the time. Restart uses Laravel's
 * queue:restart (workers end after their current job, systemd starts them
 * again), pause uses queue:pause (workers keep running but take no jobs).
 * A real stop through systemd is not offered: a stopped worker could not
 * run the job that starts it again.
 */
class PanelWorkers
{
    /**
     * Queue name => systemd unit.
     */
    public const QUEUES = [
        'default' => 'owpanel-queue.service',
        'queries' => 'owpanel-query.service',
    ];

    public const SCHEDULER_UNIT = 'owpanel-scheduler.timer';

    /**
     * A heartbeat older than this means the service does not respond.
     */
    public const STALE_AFTER = 150;

    private const KEY = 'owpanel:heartbeat:';

    /**
     * Called by the scheduler once a minute.
     */
    public static function tick(): void
    {
        self::beat('scheduler');
        foreach (array_keys(self::QUEUES) as $queue) {
            QueueHeartbeat::dispatch($queue);
        }
    }

    public static function beat(string $name): void
    {
        Cache::forever(self::KEY.$name, now()->getTimestamp());
    }

    public static function lastBeat(string $name): ?CarbonImmutable
    {
        $timestamp = Cache::get(self::KEY.$name);

        return is_int($timestamp) ? CarbonImmutable::createFromTimestamp($timestamp) : null;
    }

    public static function connection(): string
    {
        return (string) config('queue.default');
    }

    public static function isPaused(string $queue): bool
    {
        return Queue::isPaused(self::connection(), $queue);
    }

    /**
     * @return list<string>
     */
    public static function pausedQueues(): array
    {
        return array_values(array_filter(array_keys(self::QUEUES), fn (string $queue) => self::isPaused($queue)));
    }

    public static function pause(string $queue): void
    {
        Queue::pause(self::connection(), $queue);
    }

    public static function resume(string $queue): void
    {
        Queue::resume(self::connection(), $queue);
    }

    public static function restart(): void
    {
        Artisan::call('queue:restart');
    }

    public static function lastRestart(): ?CarbonImmutable
    {
        $timestamp = Cache::get('illuminate:queue:restart');

        return is_int($timestamp) ? CarbonImmutable::createFromTimestamp($timestamp) : null;
    }

    /**
     * State of a worker: paused, running (heartbeat is fresh), busy (no
     * fresh heartbeat, but it works on a job that is not older than the
     * queue's retry time), unresponsive, or unknown before the first
     * heartbeat.
     *
     * @return array{name: string, unit: string, state: string, last_beat: ?CarbonImmutable, current: ?string, current_since: ?CarbonImmutable, waiting: int, oldest_waiting: ?CarbonImmutable, failed: int, last_failed: ?CarbonImmutable}
     */
    public static function worker(string $queue): array
    {
        $lastBeat = self::lastBeat($queue);
        $jobs = DB::table('jobs')->where('queue', $queue);
        $reserved = (clone $jobs)->whereNotNull('reserved_at')->orderBy('reserved_at')->first(['payload', 'reserved_at']);
        $waiting = (clone $jobs)->whereNull('reserved_at');
        $oldest = (clone $waiting)->min('created_at');
        $failed = DB::table('failed_jobs')->where('queue', $queue);
        $lastFailed = (clone $failed)->max('failed_at');

        $currentSince = $reserved ? CarbonImmutable::createFromTimestamp((int) $reserved->reserved_at) : null;
        $retryAfter = (int) config('queue.connections.database.retry_after', 3900);

        $state = match (true) {
            self::isPaused($queue) => 'paused',
            $lastBeat !== null && $lastBeat->diffInSeconds(now()) <= self::STALE_AFTER => 'running',
            $currentSince !== null && $currentSince->diffInSeconds(now()) <= $retryAfter => 'busy',
            $lastBeat === null => 'unknown',
            default => 'unresponsive',
        };

        return [
            'name' => $queue,
            'unit' => self::QUEUES[$queue],
            'state' => $state,
            'last_beat' => $lastBeat,
            'current' => $reserved ? self::jobName((string) $reserved->payload) : null,
            'current_since' => $currentSince,
            'waiting' => $waiting->count(),
            'oldest_waiting' => $oldest !== null ? CarbonImmutable::createFromTimestamp((int) $oldest) : null,
            'failed' => $failed->count(),
            'last_failed' => $lastFailed !== null ? CarbonImmutable::parse($lastFailed) : null,
        ];
    }

    /**
     * @return array{state: string, last_beat: ?CarbonImmutable}
     */
    public static function scheduler(): array
    {
        $lastBeat = self::lastBeat('scheduler');

        return [
            'state' => match (true) {
                $lastBeat === null => 'unknown',
                $lastBeat->diffInSeconds(now()) <= self::STALE_AFTER => 'running',
                default => 'unresponsive',
            },
            'last_beat' => $lastBeat,
        ];
    }

    /**
     * Short class name of a queued job, e.g. "RunServerScript".
     */
    private static function jobName(string $payload): string
    {
        $name = json_decode($payload, true)['displayName'] ?? '';

        return is_string($name) && $name !== '' ? class_basename($name) : '–';
    }
}
