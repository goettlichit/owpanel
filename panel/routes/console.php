<?php

use App\Databases\Transfers;
use App\Jobs\SendHeartbeat;
use App\Models\AuditLog;
use App\Models\ServerMetric;
use App\Models\ServerSnapshot;
use App\Models\ServerTask;
use App\Models\SiteVisit;
use App\Support\Heartbeat;
use App\Workers\PanelWorkers;
use Illuminate\Support\Facades\Schedule;

/*
 * Run by owpanel-scheduler.timer once a minute as owpanel-worker (E-075).
 */

// Heartbeat of the scheduler and both workers for System -> Services
// (E-133).
Schedule::call(fn () => PanelWorkers::tick())
    ->name('owpanel:heartbeat')
    ->everyMinute();

// Heartbeat to an outside monitoring, if set up (E-160).
Schedule::call(function () {
    if (Heartbeat::url() !== null) {
        SendHeartbeat::dispatch();
    }
})->name('owpanel:outside-heartbeat')->everyFiveMinutes();

// State and load of all servers. Skipped while the previous run still
// waits for a slow server.
Schedule::command('owpanel:check-servers')
    ->everyMinute()
    ->withoutOverlapping(5);

// Nightly backups of the websites (E-096).
Schedule::command('owpanel:backups')
    ->everyMinute()
    ->withoutOverlapping(5);

// Hetzner snapshots at their time (E-095).
Schedule::command('owpanel:snapshots')
    ->everyMinute()
    ->withoutOverlapping(5);

// Alerts and all-clears by mail and push, held messages after the quiet
// hours (E-101).
Schedule::command('owpanel:notifications')
    ->everyMinute()
    ->withoutOverlapping(5);

// Newest release of the panel (E-154), once a day.
Schedule::command('owpanel:release-check')->dailyAt('04:17');
// Update of the panel (E-185): follow a running one every minute; the
// automatic one, if switched on, at night after the release check.
Schedule::command('owpanel:panel-update')
    ->everyMinute()
    ->withoutOverlapping(5);
Schedule::command('owpanel:panel-update', ['--auto'])->dailyAt('04:47');

// Fill level of the Storage Boxes (E-160), once an hour.
Schedule::command('owpanel:storage-boxes')
    ->hourlyAt(23)
    ->withoutOverlapping(30);

// Blocklists of the firewalls (E-170): due lists once an hour.
Schedule::command('owpanel:blocklists')
    ->hourlyAt(41)
    ->withoutOverlapping(30);

// Storage of the websites and their quotas (E-126), every 15 minutes.
Schedule::command('owpanel:storage')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30);

// Visitors of the websites from their access logs (E-148), once an hour.
Schedule::command('owpanel:site-stats')
    ->hourlyAt(7)
    ->withoutOverlapping(30);

// Measurements older than seven days, audit entries after 90 days or one
// year, finished tasks and snapshot runs after 90 days (E-092), visitor
// numbers after 14 months (E-148).
Schedule::command('model:prune', ['--model' => [ServerMetric::class, AuditLog::class, ServerTask::class, ServerSnapshot::class, SiteVisit::class]])
    ->dailyAt('03:17');

// Export and import files older than one day (E-082).
Schedule::call(fn () => Transfers::prune())
    ->name('owpanel:prune-transfers')
    ->hourly();
