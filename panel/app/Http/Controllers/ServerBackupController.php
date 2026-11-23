<?php

namespace App\Http\Controllers;

use App\Backups\BackupTarget;
use App\Backups\Rebuild;
use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Enums\TaskStatus;
use App\Hetzner\HetznerCloud;
use App\Hetzner\HetznerException;
use App\Models\Alert;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Servers\ScriptCall;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Server -> Backups (E-096 to E-100): the backup target of the server, a
 * Storage Box of the Hetzner project, with time and retention; the
 * websites with their nightly and last backup; and on the panel's own
 * server the backup of the panel. Like Server -> Snapshots, everything
 * but the API token is set here.
 */
class ServerBackupController extends Controller
{
    public function index(Server $server): View
    {
        Gate::authorize('manageBackups', $server);

        $boxes = [];
        $boxesError = null;
        if (HetznerCloud::configured()) {
            try {
                $boxes = HetznerCloud::fromSettings()->storageBoxes();
            } catch (HetznerException $e) {
                $boxesError = __('snapshots.errors.'.$e->errorCode);
            }
        }

        $sites = $server->sites()->orderBy('domain')->get();

        return view('servers.backups', [
            'server' => $server,
            'hetznerConfigured' => HetznerCloud::configured(),
            'boxes' => $boxes,
            'boxesError' => $boxesError,
            'sites' => $sites,
            'missing' => Rebuild::missingSites($server),
            // Line of the target card (docs/DESIGN.md): blue none, orange connecting,
            // green connected, red when the latest backup failed.
            'accent' => match (true) {
                ! $server->backupConfigured() => 'blue',
                Alert::whereNull('resolved_at')->where('key', 'backup_failed:'.$server->getKey())->exists() => 'red',
                ! $server->backupActive() => 'orange',
                default => 'green',
            },
            'panelTask' => $server->hostsPanel()
                ? $server->tasks()->where('script', ServerScript::PanelBackup->value)->latest('created_at')->latest('id')->first()
                : null,
        ]);
    }

    /**
     * Builds the websites the server lacks again from their newest backups,
     * after putting back its own firewall rules and fail2ban exceptions
     * (E-106).
     */
    public function rebuild(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('manageBackups', $server);
        $request->validate(['confirm' => ['accepted']]);

        if (! $server->backupActive()) {
            return back()->with('error', __('server_backups.missing.not_ready'));
        }
        $sites = Rebuild::server($server, $request->user());
        if ($sites->isEmpty()) {
            return back()->with('error', __('server_backups.missing.none'));
        }

        return redirect()->back(fallback: route('servers.backups', $server))
            ->with('status', __('server_backups.missing.started', ['sites' => $sites->pluck('domain')->implode(', ')]));
    }

    /**
     * Saves the backup target of the server and connects it: the server
     * gets a subaccount on the box and installs its key there, with a
     * one-time password that is not stored. "No backups" turns them off;
     * data and subaccount on the box stay.
     */
    public function update(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('manageBackups', $server);

        $validated = $request->validate([
            'backup_box' => ['nullable', 'integer', 'min:1'],
            'backup_path' => ['required', 'string', 'max:100', 'regex:#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#'],
            'backup_external' => ['nullable', 'boolean'],
            'backup_time' => ['required', 'date_format:H:i'],
            'backup_interval' => ['nullable', 'integer', Rule::in(Server::BACKUP_INTERVALS)],
            'backup_keep_hourly' => ['nullable', 'integer', 'between:1,168'],
            'backup_keep_daily' => ['required', 'integer', 'between:1,60'],
            'backup_keep_weekly' => ['required', 'integer', 'between:0,52'],
            'backup_keep_monthly' => ['required', 'integer', 'between:0,36'],
        ]);

        $schedule = [
            'backup_time' => $validated['backup_time'],
            'backup_interval' => (int) ($validated['backup_interval'] ?? $server->backup_interval),
            'backup_keep_hourly' => (int) ($validated['backup_keep_hourly'] ?? $server->backup_keep_hourly),
            'backup_keep_daily' => (int) $validated['backup_keep_daily'],
            'backup_keep_weekly' => (int) $validated['backup_keep_weekly'],
            'backup_keep_monthly' => (int) $validated['backup_keep_monthly'],
        ];
        $oldBox = $server->backup_box_id;

        $boxId = (int) ($validated['backup_box'] ?? 0);
        if ($boxId === 0) {
            $server->forceFill($schedule + ['backup_box_id' => null, 'backup_ready' => false])->save();
            Audit::record('server.backup_target_removed', null, ['server' => $server->name], $request->user());
            $this->applyBoxAccess($oldBox);

            return redirect()->route('servers.backups', $server)->with('status', __('settings.backup.removed'));
        }

        // The box must be one of the project; its host comes from Hetzner.
        try {
            $box = collect(HetznerCloud::fromSettings()->storageBoxes())->firstWhere('id', $boxId);
        } catch (HetznerException $e) {
            return back()->withInput()->withErrors(['backup_box' => __('snapshots.errors.'.$e->errorCode)]);
        }
        if ($box === null) {
            return back()->withInput()->withErrors(['backup_box' => __('settings.backup.box_unknown')]);
        }

        $external = $request->boolean('backup_external');
        $moved = $boxId !== $server->backup_box_id || $validated['backup_path'] !== $server->backup_path;
        $changed = $moved || $external !== (bool) $server->backup_external;
        $server->forceFill($schedule + [
            'backup_box_id' => $boxId,
            'backup_box_name' => $box['name'] !== '' ? $box['name'] : $box['username'],
            'backup_host' => $box['server'],
            'backup_path' => $validated['backup_path'],
            'backup_external' => $external,
        ])->save();
        if ($moved) {
            // A new box or directory needs a new subaccount; the old one stays.
            $server->forceFill(['backup_subaccount_id' => null, 'backup_account' => null, 'backup_ready' => false])->save();
        }
        Audit::record('server.backup_target_changed', null, ['server' => $server->name, 'box' => $box['name'] !== '' ? $box['name'] : $box['username']], $request->user());

        $failed = $this->applyBoxAccess($boxId);
        if ($oldBox !== null && $oldBox !== $boxId) {
            $this->applyBoxAccess($oldBox);
        }
        if ($failed !== null) {
            return back()->withErrors(['backup_box' => $failed]);
        }

        if ($changed || ! $server->backup_ready) {
            BackupTarget::setUp($server, $request->user());

            return redirect()->route('servers.backups', $server)->with('status', __('settings.backup.connecting_started'));
        }

        return redirect()->route('servers.backups', $server)->with('status', __('settings.backup.saved_only'));
    }

    /**
     * What one backup of the panel holds (E-099). It is restored only on
     * a new server through the installer, never into the running panel.
     */
    public function panelSnapshot(Server $server, string $snapshot): View
    {
        Gate::authorize('manageBackups', $server);
        abort_unless($server->hostsPanel() && $server->backupActive(), 404);

        return view('servers.panel-backup-show', ['server' => $server, 'snapshot' => $snapshot]);
    }

    /**
     * Backs up the panel itself now (E-099), on the server it runs on.
     */
    public function backupPanel(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('manageBackups', $server);

        if (! $server->hostsPanel() || ! $server->backupActive()) {
            return back()->with('error', __('settings.panel_backup.not_ready'));
        }
        $running = $server->tasks()->where('script', ServerScript::PanelBackup->value)
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value])->exists();
        if ($running) {
            return back()->with('error', __('settings.panel_backup.busy'));
        }

        $task = ServerTask::start($server, ScriptCall::panelBackup(true, $server->backupRetention()), $request->user());
        Audit::record('system.panel_backup_started', null, [], $request->user());

        return redirect()->back(fallback: route('servers.backups', $server));
    }

    /**
     * "Back up now" at the top of Server -> Backups: a manual backup of each
     * active website, one task each, and on its server one of the panel.
     * Websites the server lacks or with a task waiting or running are left
     * out, as is the panel while its backup runs.
     */
    public function backupAll(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('manageBackups', $server);

        if (! $server->backupActive()) {
            return back()->with('error', __('backups.toast.not_ready'));
        }

        $started = 0;
        $skipped = 0;
        $server->sites()->with('server')->where('status', SiteStatus::Active->value)->orderBy('domain')->get()
            ->each(function (Site $site) use ($server, $request, &$started, &$skipped) {
                if ($site->hasOpenTasks() || $site->missingOnServer()) {
                    $skipped++;

                    return;
                }
                ServerTask::start($server, ScriptCall::siteBackup($site->system_user, true, $server->backupRetention()), $request->user(), $site);
                Audit::record('site.backup_started', $site, ['domain' => $site->domain], $request->user());
                $started++;
            });

        $panel = false;
        if ($server->hostsPanel() && ! $server->tasks()->where('script', ServerScript::PanelBackup->value)
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value])->exists()) {
            ServerTask::start($server, ScriptCall::panelBackup(true, $server->backupRetention()), $request->user());
            Audit::record('system.panel_backup_started', null, [], $request->user());
            $panel = true;
        }

        if ($started === 0 && ! $panel) {
            return back()->with('error', __('server_backups.backup_all.none'));
        }

        $message = __($panel ? 'server_backups.backup_all.started_panel' : 'server_backups.backup_all.started', ['count' => $started]);
        if ($skipped > 0) {
            $message .= ' '.__('server_backups.backup_all.skipped', ['skipped' => $skipped]);
        }

        // Stays on the page; the lists show the backups as they run.
        return redirect()->route('servers.backups', $server)->with('status', $message);
    }

    /**
     * Sets whether a box itself is reachable from outside; returns an error
     * text when Hetzner refuses.
     */
    private function applyBoxAccess(?int $boxId): ?string
    {
        if ($boxId === null || ! HetznerCloud::configured()) {
            return null;
        }
        try {
            BackupTarget::applyBoxAccess($boxId);
        } catch (HetznerException $e) {
            return __('snapshots.errors.'.$e->errorCode);
        }

        return null;
    }
}
