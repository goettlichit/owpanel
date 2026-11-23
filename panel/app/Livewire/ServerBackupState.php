<?php

namespace App\Livewire;

use App\Enums\ServerScript;
use App\Models\Server;
use App\Models\ServerTask;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Connection of one server to its backup target on Server -> Backups
 * (E-097, E-100). While the server is being connected, the state is asked
 * for every three seconds, so it changes without a reload.
 */
class ServerBackupState extends Component
{
    #[Locked]
    public string $serverId;

    public function mount(Server $server): void
    {
        $this->serverId = (string) $server->getKey();
    }

    public function render(): View
    {
        $server = Server::findOrFail($this->serverId);
        Gate::authorize('manageBackups', $server);
        $setup = $server->tasks()->where('script', ServerScript::BackupSetup->value)
            ->latest('created_at')->latest('id')->first();

        return view('livewire.server-backup-state', [
            'server' => $server,
            'setup' => $setup instanceof ServerTask ? $setup : null,
            'connecting' => $setup !== null && ! $setup->isFinished(),
        ]);
    }
}
