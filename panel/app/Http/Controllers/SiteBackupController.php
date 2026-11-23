<?php

namespace App\Http\Controllers;

use App\Backups\Rebuild;
use App\Customers\PackageLimits;
use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Http\Controllers\Concerns\ChecksPackageLimits;
use App\Models\ServerTask;
use App\Models\Site;
use App\Servers\ScriptCall;
use App\Support\Area;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Backups of a website (E-096): nightly switch, a backup on demand and
 * the list of restic snapshots on the Storage Box, which loads after the
 * page. One snapshot can be looked into and restored, whole or in parts
 * (E-098). Customers use the same pages for their own websites (E-145):
 * backup now and restore within the daily limit of their package, without
 * the automatic backup switch, the rebuild and the server settings.
 */
class SiteBackupController extends Controller
{
    use ChecksPackageLimits;

    public function index(Site $site): View
    {
        Gate::authorize('manageBackups', $site);
        $site->load('server');

        return view('sites.backups', [
            'site' => $site,
            'configured' => $site->server->backupConfigured(),
            'ready' => $this->ready($site),
            'active' => $site->status === SiteStatus::Active,
            'lastTask' => $site->tasks()->where('script', ServerScript::SiteBackup->value)->latest('created_at')->latest('id')->first(),
            'busy' => $this->busy($site),
            'missing' => $site->missingOnServer(),
            'retention' => $site->server->backupRetention(),
            'time' => $site->server->backup_time,
            'selfService' => $this->selfService($site),
        ]);
    }

    public function toggle(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('configureBackups', $site);

        $site->forceFill(['backup_enabled' => ! $site->backup_enabled])->save();
        Audit::record($site->backup_enabled ? 'site.backup_enabled' : 'site.backup_disabled', $site, ['domain' => $site->domain], $request->user());

        return redirect()->route('sites.backups', $site)->with('status', __($site->backup_enabled ? 'backups.toast.enabled' : 'backups.toast.disabled'));
    }

    public function store(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('manageBackups', $site);
        $site->load('server');

        if (! $site->server->backupActive() || $site->status !== SiteStatus::Active) {
            return back()->with('error', __('backups.toast.not_ready'));
        }
        if ($this->busy($site)) {
            return back()->with('error', __('backups.toast.busy'));
        }
        if ($refused = $this->checkSelfService($request, $site, 'backup_started')) {
            return $refused;
        }

        $task = ServerTask::start($site->server, ScriptCall::siteBackup($site->system_user, true, $site->server->backupRetention()), $request->user(), $site);
        Audit::record('site.backup_started', $site, ['domain' => $site->domain], $request->user());

        return Area::afterTask('my.sites.backups', $site);
    }

    /**
     * One snapshot: what it holds and how to restore it (E-098).
     */
    public function show(Site $site, string $snapshot): View
    {
        Gate::authorize('manageBackups', $site);
        $site->load('server');
        abort_unless($this->ready($site) && $site->status === SiteStatus::Active, 404);

        return view('sites.backup-show', [
            'site' => $site,
            'snapshot' => $snapshot,
            'busy' => $this->busy($site),
            'missing' => $site->missingOnServer(),
            'lastRestore' => $site->tasks()->where('script', ServerScript::SiteRestore->value)->latest('created_at')->latest('id')->first(),
            'selfService' => $this->selfService($site),
        ]);
    }

    /**
     * Restores the website, or a part of it, from a snapshot. The server
     * backs up the current state first, so this can be undone.
     */
    public function restore(Request $request, Site $site, string $snapshot): RedirectResponse
    {
        Gate::authorize('manageBackups', $site);
        $site->load('server');

        $validated = $request->validate([
            'scope' => ['required', 'in:all,files,databases,path,database'],
            'path' => ['required_if:scope,path', 'nullable', 'string', 'max:1024'],
            'database' => ['required_if:scope,database', 'nullable', 'string', 'max:64'],
            'confirm' => ['accepted'],
        ]);
        $scope = $validated['scope'];
        $path = $scope === 'path' ? (string) $validated['path'] : '';
        $database = $scope === 'database' ? (string) $validated['database'] : '';
        if (! ScriptCall::validSitePath($path)) {
            return back()->withErrors(['path' => __('backups.toast.invalid_path')]);
        }
        // Only a database the panel knows for this website.
        if ($scope === 'database' && ! $site->databases()->where('name', $database)->exists()) {
            return back()->with('error', __('backups.toast.unknown_database'));
        }
        if (! $this->ready($site) || $site->status !== SiteStatus::Active) {
            return back()->with('error', __('backups.toast.not_ready'));
        }
        if ($this->busy($site)) {
            return back()->with('error', __('backups.toast.busy'));
        }
        if ($refused = $this->checkSelfService($request, $site, 'restore_started')) {
            return $refused;
        }

        $task = ServerTask::start($site->server, ScriptCall::siteRestore($site->system_user, $snapshot, $scope, $path, $database), $request->user(), $site);
        Audit::record('site.backup_restore_started', $site, array_filter([
            'domain' => $site->domain, 'snapshot' => $snapshot, 'scope' => $scope, 'path' => $path, 'database' => $database,
        ], fn ($value) => $value !== ''), $request->user());

        return Area::afterTask('my.sites.backups.show', [$site, $snapshot]);
    }

    /**
     * Builds the website again when the server lacks it (E-106), from the
     * chosen backup or, without one, from the newest.
     */
    public function rebuild(Request $request, Site $site, ?string $snapshot = null): RedirectResponse
    {
        Gate::authorize('configureBackups', $site);
        $site->load('server');
        $request->validate(['confirm' => ['accepted']]);

        if (! $this->ready($site) || $site->status !== SiteStatus::Active) {
            return back()->with('error', __('backups.toast.not_ready'));
        }
        if ($this->busy($site)) {
            return back()->with('error', __('backups.toast.busy'));
        }
        if (! $site->missingOnServer()) {
            return back()->with('error', __('backups.toast.not_missing'));
        }

        $first = Rebuild::site($site, $snapshot ?? ScriptCall::LATEST, $request->user());

        return redirect()->back(fallback: route('sites.backups', $site));
    }

    /**
     * The daily limit of the package for backups and restores customers
     * start themselves (E-125, E-145). Administrators are not limited.
     */
    private function checkSelfService(Request $request, Site $site, string $action): ?RedirectResponse
    {
        if ($request->user()->isAdmin()) {
            return null;
        }

        return $this->checkPackageLimits($request, $site->customer, PackageLimits::forSelfService($site->customer), $action, ['domain' => $site->domain]);
    }

    /**
     * Used and allowed self-service actions today, for the pages of the
     * customer area; null in the administration.
     *
     * "possible" is false without a package or when today's are used up;
     * the buttons are then disabled.
     *
     * @return array{package: bool, used: int, max: int|null, possible: bool}|null
     */
    private function selfService(Site $site): ?array
    {
        if (! Area::customer() || $site->customer === null) {
            return null;
        }
        $package = $site->customer->package;
        $used = PackageLimits::selfServiceToday($site->customer);
        $max = $package?->daily_self_service;

        return [
            'package' => $package !== null,
            'used' => $used,
            'max' => $max,
            'possible' => $package !== null && ($max === null || $used < $max),
        ];
    }

    private function ready(Site $site): bool
    {
        return $site->server->backupActive();
    }

    /**
     * A task of the website is waiting or running, e.g. a backup, a restore
     * or a step of a rebuild (E-106).
     */
    private function busy(Site $site): bool
    {
        return $site->hasOpenTasks();
    }
}
