<?php

namespace App\Livewire;

use App\Models\Server;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Last checked by the panel" under Server -> Updates. After "Check now" the
 * time is empty until the next minutely check has asked the server
 * (E-076); meanwhile it reads "Checking …", asks every five seconds and
 * reloads the page once the answer is in, so updates and hints follow.
 */
class UpdatesChecked extends Component
{
    #[Locked]
    public string $serverId;

    /** Whether the last render was still waiting for the check. */
    public bool $waited = false;

    public function mount(Server $server): void
    {
        $this->serverId = $server->getKey();
    }

    public function render(): View
    {
        $server = Server::findOrFail($this->serverId);
        Gate::authorize('view', $server);

        $waiting = $server->updates_checked_at === null;
        if (! $waiting && $this->waited) {
            $this->js('window.location.reload()');
        }
        $this->waited = $waiting;

        return view('livewire.updates-checked', ['server' => $server, 'waiting' => $waiting]);
    }
}
