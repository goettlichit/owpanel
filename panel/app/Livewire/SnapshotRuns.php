<?php

namespace App\Livewire;

use App\Models\Server;
use App\Models\ServerSnapshot;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The snapshot runs of a server, five per page (E-095). While one is pending or
 * running, the list asks for an update every five seconds and reloads the
 * page once it is done.
 */
class SnapshotRuns extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Locked]
    public string $serverId;

    /**
     * Whether a run was going on at the last update; when it ends, the
     * whole page reloads, so the list of images and the button follow.
     */
    public bool $wasRunning = false;

    public function mount(Server $server): void
    {
        $this->serverId = (string) $server->getKey();
    }

    public function render(): View
    {
        $server = Server::findOrFail($this->serverId);
        Gate::authorize('manageSnapshots', $server);
        // Five per page (?runs=2 …); a run going on is always among the newest.
        $runs = $server->snapshots()->with('user')->latest('created_at')->latest('id')->paginate(5, pageName: 'runs');

        $running = $server->snapshots()->whereIn('status', [ServerSnapshot::PENDING, ServerSnapshot::CREATING])->exists();
        if ($this->wasRunning && ! $running) {
            $this->js('window.location.reload()');
        }
        $this->wasRunning = $running;

        return view('livewire.snapshot-runs', [
            'runs' => $runs,
            'running' => $running,
        ]);
    }
}
