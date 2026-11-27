<?php

namespace App\Jobs;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Hetzner\HetznerException;
use App\Models\ServerTask;
use App\Notify\Events;
use App\Servers\ScriptCall;
use App\Servers\ScriptRunner;
use App\Servers\ScriptsUpdater;
use App\Servers\ServerConnectionException;
use App\Servers\ServerLane;
use App\Servers\StatusCheck;
use App\Servers\TaskOutput;
use App\Support\PanelRelease;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a server task in the queue worker (E-052).
 *
 * A server action is never repeated on its own: the job has one attempt,
 * and it only starts a task that is still pending. Whether a failed task
 * runs again is decided by a person.
 */
class RunServerTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    /**
     * Seconds until the worker stops the job: the longest script plus time
     * for connecting. Stays below retry_after in config/queue.php.
     */
    public int $timeout = 3700;

    /** How long a changing task waits for matching server scripts (E-194). */
    public const OUTDATED_WAIT_MINUTES = 30;

    /** Seconds between two looks while it waits. */
    public const OUTDATED_RETRY_SECONDS = 30;

    public function __construct(public readonly string $taskId) {}

    /**
     * Puts the task back to pending, frees its server for the scripts
     * update and comes back later as a new job.
     */
    private function waitForScripts(ServerTask $task): void
    {
        $task->forceFill(['status' => TaskStatus::Pending, 'started_at' => null])->save();
        ServerLane::release($task->getKey());
        self::dispatch($task->getKey())->delay(now()->addSeconds(self::OUTDATED_RETRY_SECONDS));
    }

    public function handle(ScriptRunner $runner): void
    {
        // Moves the task from pending to running, so two workers never both
        // start it. One task at a time per server; tasks of other servers
        // run in the other workers. A task whose server is busy comes back
        // a little later as a new job, so the single try stays (M5-1).
        $start = ServerLane::start($this->taskId);
        if ($start === ServerLane::BUSY) {
            self::dispatch($this->taskId)->delay(now()->addSeconds(ServerLane::RETRY_SECONDS));

            return;
        }
        if ($start !== ServerLane::STARTED) {
            return;
        }

        $task = ServerTask::with('server')->findOrFail($this->taskId);
        $output = new TaskOutput($task);

        // Scripts of another version may not know what the panel asks for:
        // only updating, reading, backups and maintenance run until the
        // panel has brought them up to date (E-167). The stored version may
        // be from before an update of the panel, so the server is asked
        // first; while the scripts still differ, the task waits up to
        // half an hour instead of failing at once (E-194).
        if (! $task->script->runsWhenOutdated() && $task->server->scriptsOutdated()) {
            (new StatusCheck($runner))->run($task->server);
            $task->server->refresh();
        }
        if (! $task->script->runsWhenOutdated() && $task->server->scriptsOutdated()) {
            ScriptsUpdater::ensure($task->server);
            if (blank($task->output)) {
                $output->append(__('tasks.outdated_log', ['scripts' => $task->server->scripts_version, 'panel' => PanelRelease::installed()])."\n");
                $output->flush();
            }
            if ($task->created_at->greaterThan(now()->subMinutes(self::OUTDATED_WAIT_MINUTES))) {
                $this->waitForScripts($task);

                return;
            }
            $this->finish($task, TaskStatus::Failed, error: 'scripts_outdated');

            return;
        }

        try {
            $call = $task->call();
        } catch (HetznerException $e) {
            // Preparing the call needed the Hetzner API, e.g. for the
            // subaccount of the backup target (E-097).
            $output->append('Hetzner API: '.$e->getMessage()."\n");
            $output->flush();
            $this->finish($task, TaskStatus::Failed, error: 'hetzner_'.$e->errorCode);

            return;
        }

        try {
            $result = $runner->run($task->server, $call, $output->append(...));
        } catch (ServerConnectionException $e) {
            $output->flush();
            $this->finish($task, TaskStatus::Failed, error: $e->errorCode);

            return;
        }

        $output->flush();
        $this->finish(
            $task,
            $result->succeeded() ? TaskStatus::Succeeded : TaskStatus::Failed,
            error: $result->error(),
            exitCode: $result->exitCode,
            result: $result->json,
        );

        $this->confirmFirewall($task, $runner);
    }

    /**
     * A firewall change is rolled back after 60 seconds unless the panel
     * confirms it over a new SSH connection (E-044, E-090). The confirmation
     * runs right here instead of waiting in the queue behind other tasks;
     * the queued job for it then finds the task no longer pending.
     */
    private function confirmFirewall(ServerTask $task, ScriptRunner $runner): void
    {
        if ($task->script !== ServerScript::FirewallRules || $task->status !== TaskStatus::Succeeded
            || ($task->result['firewall_confirm_required'] ?? false) !== true) {
            return;
        }

        $confirm = ServerTask::start($task->server, ScriptCall::firewallConfirm(), $task->user);
        (new self($confirm->getKey()))->handle($runner);
    }

    /**
     * Called by the worker when the job crashed or ran too long.
     */
    public function failed(?Throwable $exception): void
    {
        $task = ServerTask::find($this->taskId);

        if ($task !== null && ! $task->isFinished()) {
            $this->finish($task, TaskStatus::Failed, error: 'worker_failed');
        }
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function finish(ServerTask $task, TaskStatus $status, ?string $error = null, ?int $exitCode = null, ?array $result = null): void
    {
        ServerLane::release($task->getKey());
        $task->forceFill([
            'status' => $status,
            'error' => $error,
            'exit_code' => $exitCode,
            'result' => $result,
            'started_at' => $task->started_at ?? now(),
            'finished_at' => now(),
        ])->save();

        // Websites and databases follow the outcome of their scripts.
        $task->site?->taskFinished($task);
        $task->database?->taskFinished($task);
        if ($task->script === ServerScript::BackupSetup) {
            $task->server->backupSetupFinished($task);
        }
        // New scripts count at once, not only after the next status check
        // (E-187).
        if ($task->script === ServerScript::ServerScriptsUpdate && $status === TaskStatus::Succeeded
            && is_string($result['version'] ?? null) && preg_match('/^[0-9A-Za-z.-]{1,64}$/', $result['version'])) {
            $task->server->forceFill(['scripts_version' => $result['version']])->save();
        }
        // After refreshing or installing, the update state is read again (E-154).
        if ($task->script === ServerScript::ServerUpgrade) {
            $task->server->forceFill(['updates_checked_at' => null])->save();
        }
        Events::taskFinished($task);
        // Tasks that wait for this one start now, or are skipped (E-106).
        $task->releaseFollowers();
    }
}
