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

    public function __construct(public readonly string $taskId) {}

    public function handle(ScriptRunner $runner): void
    {
        // Moves the task from pending to running in one statement, so two
        // workers can never both start it.
        $claimed = ServerTask::whereKey($this->taskId)
            ->where('status', TaskStatus::Pending->value)
            ->update(['status' => TaskStatus::Running->value, 'started_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $task = ServerTask::with('server')->findOrFail($this->taskId);
        $output = new TaskOutput($task);

        // Scripts of another version may not know what the panel asks for:
        // only updating, reading, backups and maintenance run until the
        // panel has brought them up to date (E-167).
        if ($task->server->scriptsOutdated() && ! $task->script->runsWhenOutdated()) {
            $output->append(__('tasks.outdated_log', ['scripts' => $task->server->scripts_version, 'panel' => PanelRelease::installed()])."\n");
            $output->flush();
            $this->finish($task, TaskStatus::Failed, error: 'scripts_outdated');
            ScriptsUpdater::ensure($task->server);

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
