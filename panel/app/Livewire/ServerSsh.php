<?php

namespace App\Livewire;

use App\Enums\ServerScript;
use App\Jobs\ReadSsh;
use App\Models\Server;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Defer;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * SSH settings and accounts of a server (E-155). Reading them means a round
 * trip to the server; the page shows at once and the settings follow.
 */
#[Defer]
class ServerSsh extends Component
{
    #[Locked]
    public string $serverId;

    public function mount(Server $server): void
    {
        $this->serverId = (string) $server->getKey();
    }

    public function placeholder(): View
    {
        return view('livewire.card-loading', ['title' => __('ssh.title')]);
    }

    public function render(): View
    {
        $server = Server::findOrFail($this->serverId);
        Gate::authorize('manageSsh', $server);

        return view('livewire.server-ssh', [
            'server' => $server,
            'ssh' => ReadSsh::await($this->serverId),
            'lastTask' => $server->tasks()->where('script', ServerScript::ServerSsh->value)->latest('id')->first(),
        ]);
    }
}
