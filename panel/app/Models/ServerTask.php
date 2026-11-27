<?php

namespace App\Models;

use App\Backups\BackupTarget;
use App\Databases\Transfers;
use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Jobs\RunServerTask;
use App\Servers\ScriptCall;
use App\Servers\ScriptsArchive;
use App\Support\Audit;
use Database\Factories\ServerTaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Lang;

/**
 * One run of an approved server script (E-052). Created as "pending" by
 * start(), then run by the queue worker (App\Jobs\RunServerTask).
 */
class ServerTask extends Model
{
    /** @use HasFactory<ServerTaskFactory> */
    use HasFactory, HasUlids, MassPrunable;

    /**
     * Finished tasks are removed after 90 days (E-092). Their output can hold
     * domains, user names or addresses; pending and running tasks stay.
     */
    public const KEEP_DAYS = 90;

    /** Error code of a task that did not run because one before it failed (E-106). */
    public const SKIPPED = 'previous_failed';

    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Status, output and result are set by the job only.
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'script' => ServerScript::class,
            'arguments' => 'array',
            'status' => TaskStatus::class,
            'result' => 'array',
            'exit_code' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Creates a task, records it in the audit log and queues it. The web
     * request never talks to the server itself (docs/ARCHITEKTUR.md).
     *
     * With $after the task waits for that task and starts only when it
     * succeeded; if it failed, this one is skipped (E-106). $batch marks
     * tasks started together, e.g. one run of scheduled backups.
     */
    public static function start(Server $server, ScriptCall $call, ?User $user, ?Site $site = null, ?Database $database = null, ?string $transferFile = null, ?self $after = null, ?string $batch = null): self
    {
        $task = new self;
        $task->forceFill([
            'server_id' => $server->getKey(),
            'site_id' => $site?->getKey(),
            'database_id' => $database?->getKey(),
            'after_task_id' => $after?->getKey(),
            'batch' => $batch,
            'user_id' => $user?->getKey(),
            'script' => $call->script,
            'arguments' => $call->arguments,
            'transfer_file' => $transferFile,
        ])->save();

        Audit::record('server.task_started', $task, array_filter([
            'server' => $server->getKey(),
            'site' => $site?->getKey(),
            'script' => $call->script->value,
        ], fn ($value) => $value !== null), $user);

        if ($after === null) {
            RunServerTask::dispatch($task->getKey())->afterCommit();

            return $task;
        }

        // Read again after saving: if the previous task ended in between,
        // its end did not see this one yet. Starting twice does no harm,
        // the job only claims a pending task.
        $status = self::whereKey($after->getKey())->value('status');
        match ($status instanceof TaskStatus ? $status : TaskStatus::tryFrom((string) $status)) {
            TaskStatus::Succeeded => RunServerTask::dispatch($task->getKey())->afterCommit(),
            TaskStatus::Failed, null => $task->skip(),
            default => null,
        };

        return $task;
    }

    /**
     * Starts the tasks that waited for this one when it succeeded, and
     * skips them, with all that wait for them, when it failed (E-106).
     * Called when the task has ended.
     */
    public function releaseFollowers(): void
    {
        self::where('after_task_id', $this->getKey())
            ->where('status', TaskStatus::Pending->value)
            ->get()
            ->each(fn (self $follower) => $this->status === TaskStatus::Succeeded
                ? RunServerTask::dispatch($follower->getKey())
                : $follower->skip());
    }

    /**
     * Ends a waiting task without running it, because a task before it
     * failed. Websites and databases stay as they are, and no message goes
     * out; the failed task has reported already.
     */
    public function skip(): void
    {
        $skipped = self::whereKey($this->getKey())
            ->where('status', TaskStatus::Pending->value)
            ->update([
                'status' => TaskStatus::Failed->value,
                'error' => self::SKIPPED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        if ($skipped === 1) {
            $this->refresh();
            $this->releaseFollowers();
        }
    }

    /**
     * The task that this one waits for, if any (E-106).
     *
     * @return BelongsTo<self, $this>
     */
    public function after(): BelongsTo
    {
        return $this->belongsTo(self::class, 'after_task_id');
    }

    /**
     * The call to run. db-create gets the password of its database on stdin,
     * read from the encrypted column only now (E-078).
     */
    public function call(): ScriptCall
    {
        $database = $this->database;

        return match (true) {
            // Export and import need their database and its file (E-082).
            $this->script === ServerScript::DbExport && $database !== null => ScriptCall::dbExport($database->name, $database->password, Transfers::path((string) $this->transfer_file)),
            $this->script === ServerScript::DbImport && $database !== null => ScriptCall::dbImport($database->name, $database->password, Transfers::path((string) $this->transfer_file), in_array('--gzip', $this->arguments, true)),
            in_array($this->script, [ServerScript::DbExport, ServerScript::DbImport], true) => throw new \RuntimeException('The database of this task no longer exists.'),
            // The jobs as they are when the task runs, so the last change wins (E-088).
            $this->script === ServerScript::SiteCron && $this->site !== null => ScriptCall::siteCron(
                $this->site->system_user,
                $this->site->cronJobs()->where('enabled', true)->orderBy('created_at')->get(['schedule', 'command'])
                    ->map(fn (CronJob $job) => ['schedule' => $job->schedule, 'command' => $job->command])->all(),
            ),
            // The own nginx rules as they are when the task runs (E-184).
            $this->script === ServerScript::SiteNginx && $this->site !== null => ScriptCall::siteNginx(
                $this->site->system_user,
                $this->site->nginx_headers ?? [],
                $this->site->nginx_blocked_paths ?? [],
            ),
            // The scripts of the release that runs now, with its archive
            // and signature (E-187).
            $this->script === ServerScript::ServerScriptsUpdate => ScriptsArchive::call(),
            // The SFTP state as it is when the task runs (E-089).
            $this->script === ServerScript::SiteSftp && $this->site !== null => $this->site->sftpCall(),
            // Backup target with its secrets as they are now (E-096).
            $this->script === ServerScript::BackupSetup => BackupTarget::call($this->server),
            // Firewall rules and fail2ban exceptions as they are now (E-090).
            $this->script === ServerScript::FirewallRules => $this->server->firewallCall(),
            $this->script === ServerScript::ServerFail2ban && ($this->arguments[1] ?? null) === 'ignore' => $this->server->fail2banIgnoreCall(),
            // The pinned certificate of the website's backend as the last
            // status check saw it (E-186, E-195).
            $this->script === ServerScript::GatewaySite && in_array('--backend-cert', $this->arguments, true) => new ScriptCall(
                $this->script,
                $this->arguments,
                $this->site?->server?->backend_cert ?? throw new \RuntimeException('The certificate of the backend is not known yet.'),
            ),
            // All blocklists and exceptions as they are now (E-170).
            $this->script === ServerScript::ServerBlocklist => $this->server->blocklistCall(),
            default => new ScriptCall(
                $this->script,
                $this->arguments,
                ($stdin = $this->script === ServerScript::DbCreate ? $database?->pendingPassword() : null) === null ? null : $stdin."\n",
            ),
        };
    }

    /**
     * Toast text when the task ends while its page is open. An export says
     * where its file can be downloaded (E-082, E-083).
     */
    public function outcomeMessage(): string
    {
        $script = __('tasks.scripts.'.$this->script->value);

        return match (true) {
            $this->status !== TaskStatus::Succeeded => __('tasks.toast.failed', ['script' => $script, 'error' => $this->errorMessage()]),
            $this->script === ServerScript::DbExport => __('tasks.toast.exported', ['hours' => Transfers::KEEP_HOURS]),
            default => __('tasks.toast.succeeded', ['script' => $script]),
        };
    }

    /**
     * Where "Back" leads from this task: the object it worked on, or its
     * list after a deletion. Decided by the script, so a page opened before
     * a deletion finished does not point to something that is gone.
     *
     * @return array{url: string, label: string, name: string}
     */
    public function backTarget(): array
    {
        $target = $this->backDestination();

        return $target + ['label' => __('tasks.back.to', ['name' => $target['name']])];
    }

    /**
     * @return array{url: string, name: string}
     */
    private function backDestination(): array
    {
        $site = $this->site;
        $database = $this->database;
        $server = $this->server;

        return match (true) {
            $this->script === ServerScript::SiteDelete || (in_array($this->script, [ServerScript::SiteCreate, ServerScript::GatewaySite], true) && $site === null) => [
                'url' => route('sites.index'),
                'name' => __('tasks.back.sites'),
            ],
            $this->script === ServerScript::DbCreate && $database !== null => [
                'url' => route('databases.show', $database),
                'name' => $database->name,
            ],
            in_array($this->script, [ServerScript::DbExport, ServerScript::DbImport], true) && $database !== null => [
                'url' => route('databases.transfer', $database),
                'name' => $database->name,
            ],
            in_array($this->script, [ServerScript::DbCreate, ServerScript::DbDelete], true) && $site !== null => [
                'url' => route('databases.index', $site),
                'name' => __('tasks.back.databases'),
            ],
            $this->script === ServerScript::SiteCron && $site !== null => [
                'url' => route('sites.cron', $site),
                'name' => __('tasks.back.cron'),
            ],
            $this->script === ServerScript::SiteNginx && $site !== null => [
                'url' => route('sites.webserver', $site),
                'name' => __('tasks.back.webserver'),
            ],
            in_array($this->script, [ServerScript::SiteBackup, ServerScript::SiteRestore], true) && $site !== null => [
                'url' => route('sites.backups', $site),
                'name' => __('tasks.back.backups'),
            ],
            in_array($this->script, [ServerScript::PanelBackup, ServerScript::BackupSetup], true) => [
                'url' => route('servers.backups', $this->server),
                'name' => __('tasks.back.backups'),
            ],
            $this->script === ServerScript::SiteSftp && $site !== null => [
                'url' => route('sites.sftp', $site),
                'name' => __('tasks.back.sftp'),
            ],
            $site !== null => [
                'url' => route('sites.show', $site),
                'name' => $site->display_domain,
            ],
            in_array($this->script, [ServerScript::ServerReboot, ServerScript::ServerUpdates], true) => [
                'url' => route('servers.updates', $server),
                'name' => __('tasks.back.updates'),
            ],
            default => [
                'url' => route('servers.show', $server),
                'name' => $server->display_name,
            ],
        };
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::whereIn('status', [TaskStatus::Succeeded->value, TaskStatus::Failed->value])
            ->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * Translated text for the error code; unknown codes are named as such.
     */
    public function errorMessage(): ?string
    {
        if ($this->error === null) {
            return null;
        }

        return Lang::has('tasks.errors.'.$this->error)
            ? __('tasks.errors.'.$this->error)
            : __('tasks.errors.unknown', ['code' => $this->error]);
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    /**
     * Duration in seconds, up to now while the task is running.
     */
    public function durationInSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), true);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * The database the task works on, if any. Null after it was deleted.
     *
     * @return BelongsTo<Database, $this>
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    /**
     * The website the task works on, if any. Null after the site was deleted.
     *
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
