<?php

namespace App\Livewire;

use App\Backups\Rebuild;
use App\Enums\ServerScript;
use App\Models\Server;
use App\Models\ServerTask;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The websites of a server under Server -> Backups with their latest backup.
 * While one of those backups waits or runs, the browser asks for an update
 * every three seconds, so "Back up now" can stay on the page (E-049).
 */
class ServerSiteBackups extends Component
{
    #[Locked]
    public string $serverId;

    public function mount(Server $server): void
    {
        $this->serverId = $server->getKey();
    }

    public function render(): View
    {
        $server = Server::findOrFail($this->serverId);
        Gate::authorize('manageBackups', $server);

        $sites = $server->sites()->with('server')->orderBy('domain')->get();
        $lastBackups = ServerTask::query()
            ->where('script', ServerScript::SiteBackup->value)
            ->whereIn('site_id', $sites->modelKeys())
            ->latest('created_at')->latest('id')
            ->get()
            ->unique('site_id')
            ->keyBy('site_id');

        return view('livewire.server-site-backups', [
            'server' => $server,
            'sites' => $sites,
            'lastBackups' => $lastBackups,
            'missing' => Rebuild::missingSites($server),
            'running' => $lastBackups->contains(fn (ServerTask $task) => ! $task->isFinished()),
        ]);
    }
}
