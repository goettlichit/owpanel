<?php

namespace App\Console\Commands;

use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Servers\ScriptCall;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Starts the nightly backups of the websites (E-096) and of the panel
 * itself (E-099). Runs every minute; from each server's backup time on
 * (E-100), each active website of a connected server with backups switched
 * on, and the panel on its own server, gets one scheduled backup per slot:
 * once a day, or every 12, 6, 3 or 1 hours from that time on (E-103). The
 * tasks run one after another through the queue. A website waits while
 * another of its tasks runs and is left out while the server lacks it
 * (E-106). The tasks started for a server in one call share a batch, so
 * the message "backup finished" names exactly them; a website created
 * after the run gets its own, later batch.
 */
class RunBackups extends Command
{
    protected $signature = 'owpanel:backups';

    protected $description = 'Start the scheduled backups of the websites and the panel';

    public function handle(): int
    {
        // Each server at its own time and interval (E-100, E-103).
        Server::where('backup_ready', true)->whereNotNull('backup_box_id')->each(function (Server $server) {
            // A scheduled run counts from the start of the current slot on;
            // after the time is moved later in the day, the backup runs again.
            $startOfDay = $server->backupSlot();
            if ($startOfDay === null) {
                return;
            }
            $batch = (string) Str::ulid();
            if ($server->hostsPanel()) {
                $this->backUpPanel($server, $startOfDay, $batch);
            }
            $server->sites()->where('status', SiteStatus::Active->value)->where('backup_enabled', true)->each(
                function (Site $site) use ($server, $startOfDay, $batch) {
                    // One scheduled backup per slot, and none while the previous
                    // one still waits or runs; arguments tell scheduled from manual.
                    $done = $site->tasks()->where('script', ServerScript::SiteBackup->value)
                        ->where(fn ($query) => $query->where('created_at', '>=', $startOfDay)
                            ->orWhereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value]))
                        ->get()
                        ->contains(fn (ServerTask $task) => ! in_array('--manual', (array) $task->arguments, true));
                    if ($done) {
                        return;
                    }
                    // Not while another task changes the website, e.g. a step
                    // of a rebuild, and not while the server lacks it (E-106).
                    if ($site->hasOpenTasks() || $site->missingOnServer()) {
                        return;
                    }
                    ServerTask::start($server, ScriptCall::siteBackup($site->system_user, false, $server->backupRetention()), null, $site, batch: $batch);
                    $this->line("{$site->domain}: backup started");
                },
            );
        });

        return self::SUCCESS;
    }

    private function backUpPanel(Server $server, CarbonInterface $startOfDay, string $batch): void
    {
        $done = $server->tasks()->where('script', ServerScript::PanelBackup->value)
            ->where(fn ($query) => $query->where('created_at', '>=', $startOfDay)
                ->orWhereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value]))
            ->get()
            ->contains(fn (ServerTask $task) => ! in_array('--manual', (array) $task->arguments, true));
        if (! $done) {
            ServerTask::start($server, ScriptCall::panelBackup(false, $server->backupRetention()), null, batch: $batch);
            $this->line('panel: backup started');
        }
    }
}
