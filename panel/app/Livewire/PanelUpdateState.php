<?php

namespace App\Livewire;

use App\Models\Server;
use App\Support\PanelUpdate;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * State of the last update of the panel under Server -> Updates (E-185).
 * While an update runs it asks every five seconds and reloads the page
 * once it has ended, so versions and buttons follow.
 */
class PanelUpdateState extends Component
{
    #[Locked]
    public string $serverId;

    /** Whether the last render was still waiting for the update. */
    public bool $waited = false;

    public function mount(Server $server): void
    {
        $this->serverId = $server->getKey();
    }

    public function render(): View
    {
        $server = Server::findOrFail($this->serverId);
        Gate::authorize('checkUpdates', $server);

        $update = PanelUpdate::current();
        $running = PanelUpdate::inProgress();
        if (! $running && $this->waited) {
            $this->js('window.location.reload()');
        }
        $this->waited = $running;

        return view('livewire.panel-update-state', ['update' => $update, 'running' => $running]);
    }
}
