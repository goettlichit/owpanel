<?php

namespace App\Servers;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\ServerTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;

/**
 * One task at a time per server (milestone 5, M5-1). Several workers run
 * tasks side by side, but each server has a single lane: the column
 * servers.busy_task_id names the task that holds it.
 *
 * A task whose server is busy is dispatched again a few seconds later. The
 * confirmation of a firewall change does not wait: its time runs out
 * otherwise, and it only follows the change that held the lane just
 * before.
 */
final class ServerLane
{
    /** The task now runs and holds its server's lane where it needs one. */
    public const STARTED = 'started';

    /** Another task of the server runs; try again later. */
    public const BUSY = 'busy';

    /** The task is no longer pending, e.g. a second job for it. */
    public const GONE = 'gone';

    public const RETRY_SECONDS = 5;

    /**
     * Moves the task from pending to running and takes its server's lane,
     * both in one transaction with the rows locked, so two workers never
     * both start a task or both hold a lane. A lane whose holder no longer
     * runs (e.g. its worker died) is taken over.
     */
    public static function start(string $taskId): string
    {
        return DB::transaction(function () use ($taskId): string {
            $task = ServerTask::query()->whereKey($taskId)->lockForUpdate()->first(['id', 'server_id', 'script', 'status']);
            if ($task === null || $task->status !== TaskStatus::Pending) {
                return self::GONE;
            }

            if (self::needsLane($task->script)) {
                $holder = Server::query()->whereKey($task->server_id)->lockForUpdate()->value('busy_task_id');
                if ($holder !== null && $holder !== $task->getKey()
                    && ServerTask::whereKey($holder)->where('status', TaskStatus::Running->value)->exists()) {
                    return self::BUSY;
                }
                Server::whereKey($task->server_id)->update(['busy_task_id' => $task->getKey()]);
            }

            ServerTask::whereKey($taskId)->update(['status' => TaskStatus::Running->value, 'started_at' => now(), 'updated_at' => now()]);

            return self::STARTED;
        });
    }

    /**
     * Gives the lane back, if the task holds it.
     */
    public static function release(string $taskId): void
    {
        Server::where('busy_task_id', $taskId)->update(['busy_task_id' => null]);
    }

    private static function needsLane(ServerScript $script): bool
    {
        if ($script === ServerScript::FirewallConfirm) {
            return false;
        }

        // The sync queue of the tests runs a job inside the one that
        // started it; waiting there would never end.
        return Queue::getFacadeRoot() instanceof QueueFake
            || config('queue.connections.'.config('queue.default').'.driver') !== 'sync';
    }
}
