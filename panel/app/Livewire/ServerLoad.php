<?php

namespace App\Livewire;

use App\Models\Server;
use App\Servers\LoadHistory;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Current values and charts of a server's load (E-075). The page updates
 * itself once a minute, as often as new measurements arrive. The range is
 * part of the address, so a link keeps it.
 */
class ServerLoad extends Component
{
    #[Locked]
    public string $serverId;

    #[Url]
    public string $range = LoadHistory::DEFAULT_RANGE;

    public function mount(Server $server): void
    {
        $this->serverId = $server->getKey();
    }

    public function render(): View
    {
        $server = Server::with('latestMetric')->findOrFail($this->serverId);

        // Checked on every update, not only when the page is first shown.
        Gate::authorize('view', $server);

        if (! array_key_exists($this->range, LoadHistory::RANGES)) {
            $this->range = LoadHistory::DEFAULT_RANGE;
        }

        return view('livewire.server-load', [
            'server' => $server,
            'metric' => $server->latestMetric,
            'history' => LoadHistory::for($server, $this->range),
            'ranges' => array_keys(LoadHistory::RANGES),
        ]);
    }
}
