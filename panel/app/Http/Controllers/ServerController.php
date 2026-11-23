<?php

namespace App\Http\Controllers;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Hetzner\HetznerCloud;
use App\Models\Server;
use App\Models\ServerTask;
use App\Servers\ScriptCall;
use App\Support\Audit;
use App\Support\PanelRelease;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ServerController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Server::class);

        return view('servers.index', [
            'servers' => Server::orderBy('name')->get(),
            // Snapshots and backups per server at a glance (E-095, E-100).
            'hetznerConfigured' => HetznerCloud::configured(),
        ]);
    }

    /**
     * All tasks of the server, its own and those of its websites.
     */
    public function tasks(Server $server): View
    {
        Gate::authorize('view', $server);

        return view('servers.tasks', [
            'server' => $server,
            'tasks' => $server->tasks()->with(['user', 'site'])->latest('created_at')->latest('id')->paginate(20),
        ]);
    }

    public function show(Server $server): View
    {
        Gate::authorize('view', $server);

        return view('servers.show', [
            'server' => $server,
        ]);
    }

    /**
     * Load charts; the content is the Livewire component ServerLoad.
     */
    public function load(Server $server): View
    {
        Gate::authorize('view', $server);

        return view('servers.load', ['server' => $server]);
    }

    /**
     * Pending and installed updates, reboot (E-076).
     */
    public function updates(Server $server): View
    {
        Gate::authorize('view', $server);

        return view('servers.updates', [
            'server' => $server,
            'rebootBusy' => $this->rebootBusy($server),
        ]);
    }

    /**
     * The next minutely check also asks for updates and certificates.
     */
    /**
     * "Check now" (E-154): refreshes the package lists of all sources on the
     * server; when that is done, the update state is read again.
     */
    public function checkUpdates(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('checkUpdates', $server);
        if ($this->upgradeRunning($server)) {
            return back()->with('error', __('servers.updates.busy'));
        }

        ServerTask::start($server, ScriptCall::serverUpgrade(false), $request->user());

        return redirect()->back(fallback: route('servers.updates', $server));
    }

    /**
     * Installs everything the last check listed (apt-get dist-upgrade).
     */
    public function installUpdates(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('upgrade', $server);
        if ($this->upgradeRunning($server)) {
            return back()->with('error', __('servers.updates.busy'));
        }
        if ((int) ($server->updates['pending'] ?? 0) === 0) {
            return back()->with('error', __('servers.updates.nothing'));
        }

        ServerTask::start($server, ScriptCall::serverUpgrade(true), $request->user());
        Audit::record('server.updates_installed', $server, ['pending' => (int) $server->updates['pending']], $request->user());

        return redirect()->back(fallback: route('servers.updates', $server));
    }

    /**
     * "Check now" for the panel's own release (E-154).
     */
    public function checkRelease(Server $server): RedirectResponse
    {
        Gate::authorize('checkUpdates', $server);
        abort_unless($server->hostsPanel(), 404);
        PanelRelease::check();

        return redirect()->route('servers.updates', $server);
    }

    private function upgradeRunning(Server $server): bool
    {
        return $server->tasks()->where('script', ServerScript::ServerUpgrade->value)
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value])->exists();
    }

    /**
     * Restarts the server after its name has been typed in. The script
     * schedules the reboot 15 seconds later and reports back first.
     */
    public function reboot(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('reboot', $server);

        $validated = $request->validate([
            'confirm_name' => ['required', 'string', 'max:253'],
        ]);

        $typed = strtolower(trim($validated['confirm_name']));
        if (! in_array($typed, array_filter([strtolower($server->name), $server->hostname]), true)) {
            throw ValidationException::withMessages([
                'confirm_name' => __('servers.reboot.confirm_mismatch'),
            ]);
        }

        if ($this->rebootBusy($server)) {
            return back()->with('error', __('servers.reboot.busy'));
        }

        $task = ServerTask::start($server, ScriptCall::serverReboot(), $request->user());
        Audit::record('server.reboot_requested', $server, ['server' => $server->getKey()], $request->user());

        return redirect()->back(fallback: route('servers.show', $server));
    }

    private function rebootBusy(Server $server): bool
    {
        return $server->tasks()
            ->where('script', ServerScript::ServerReboot->value)
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value])
            ->exists();
    }

    /**
     * Starts the harmless test script and opens the task, where status and
     * log appear while it runs.
     */
    public function test(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('runTest', $server);

        $validated = $request->validate([
            'fail' => ['sometimes', 'boolean'],
        ]);

        $task = ServerTask::start(
            $server,
            ScriptCall::panelTest(steps: 5, fail: (bool) ($validated['fail'] ?? false)),
            $request->user(),
        );

        return redirect()->back(fallback: route('servers.show', $server));
    }
}
