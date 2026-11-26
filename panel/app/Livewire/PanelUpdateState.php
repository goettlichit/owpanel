<?php

namespace App\Livewire;

use App\Models\Server;
use App\Support\PanelUpdate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * State of the last update of the panel under Server -> Updates (E-185).
 * While an update runs it asks every five seconds and reloads the page
 * once it has ended, so versions, buttons and the message follow.
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
        // The message comes with the reload, when the update has really
        // ended, not when the task that started it was done (E-185). A
        // redirect, because Livewire drops flashed messages without one.
        if (! $running && $this->waited) {
            if ($update['state'] === 'succeeded') {
                session()->flash('status', __('servers.updates.panel.finished', ['version' => $update['version']]));
            } elseif ($update['state'] === 'failed') {
                session()->flash('error', __('servers.updates.panel.state.failed', ['version' => $update['version'], 'error' => $update['error'] !== null && Lang::has('tasks.errors.'.$update['error']) ? __('tasks.errors.'.$update['error']) : $update['error']]));
            }
            $this->redirect(route('servers.updates', $server));
        }
        $this->waited = $running;

        return view('livewire.panel-update-state', ['update' => $update, 'running' => $running]);
    }
}
